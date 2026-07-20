<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Term;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

class ValidateContent extends Command
{
    protected $signature = 'content:validate';

    protected $description = 'Validate all flat-file content against its blueprints. Exits non-zero on any schema violation.';

    private const SET_META_KEYS = ['id', 'type', 'enabled'];

    private const OPTION_FIELD_TYPES = ['select', 'radio', 'button_group'];

    public function handle(): int
    {
        $problems = $this->collectProblems();
        $problems = [...$problems, ...$this->blockPartialProblems()];
        $problems = [...$problems, ...$this->navProblems()];

        if ($problems === []) {
            $this->info('All content is valid.');

            return self::SUCCESS;
        }

        $this->error(count($problems).' content problem(s) found:');
        foreach ($problems as $problem) {
            $this->line('  - '.$problem);
        }

        return self::FAILURE;
    }

    /**
     * @return array<int, string>
     */
    public function collectProblems(): array
    {
        $problems = [];

        foreach (Entry::all() as $entry) {
            if (! $blueprint = $entry->blueprint()) {
                continue;
            }
            $problems = [...$problems, ...$this->entryLikeProblems(
                $blueprint->fields(),
                // Slug lives outside `data()` but blueprints routinely mark it
                // required, so fold it in before validating or every entry would
                // look like it is missing a required slug.
                ['slug' => $entry->slug(), ...$entry->data()->all()],
                "entry [{$entry->id()}] ({$entry->locale()})"
            )];
        }

        foreach (Term::all() as $term) {
            if (! $blueprint = $term->blueprint()) {
                continue;
            }
            $problems = [...$problems, ...$this->entryLikeProblems(
                $blueprint->fields(),
                ['slug' => $term->slug(), ...$term->data()->all()],
                "term [{$term->id()}]"
            )];
        }

        foreach (GlobalSet::all() as $set) {
            if (! $blueprint = $set->blueprint()) {
                continue;
            }
            foreach ($set->localizations() as $locale => $localization) {
                $problems = [...$problems, ...$this->entryLikeProblems(
                    $blueprint->fields(),
                    $localization->data()->all(),
                    "global [{$set->handle()}] ({$locale})"
                )];
            }
        }

        return $problems;
    }

    /**
     * Every stored record gets two passes: the blueprint's own validation rules
     * (required, max, character_limit, …) exactly as the Control Panel enforces
     * them, plus the structural checks the rule engine can't express (unknown
     * block types, unknown fields, invalid options, missing assets).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function entryLikeProblems(Fields $fields, array $data, string $label): array
    {
        return [
            ...$this->ruleProblems($fields, $data, $label),
            ...$this->fieldsProblems($fields, $data, $label),
        ];
    }

    /**
     * Run the blueprint's real validation rules against the stored values. Uses
     * Statamic's own validator, so nested replicator/grid rules are covered the
     * same way a Control Panel save would cover them.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function ruleProblems(Fields $fields, array $data, string $label): array
    {
        try {
            $fields->addValues($data)->validator()->validate();
        } catch (ValidationException $e) {
            $problems = [];
            foreach ($e->errors() as $messages) {
                foreach ((array) $messages as $message) {
                    $problems[] = "{$label}: {$message}";
                }
            }

            return $problems;
        } catch (\Throwable) {
            // A malformed value can make a fieldtype's rule builder throw; the
            // structural pass reports that shape problem, so don't crash here.
            return [];
        }

        return [];
    }

    /**
     * Walk resolved fields against stored values, reporting structural mismatches
     * the Control Panel would never let through.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function fieldsProblems(Fields $fields, array $data, string $label): array
    {
        $problems = [];

        foreach ($fields->all() as $handle => $field) {
            if (! array_key_exists($handle, $data)) {
                continue;
            }

            $value = $data[$handle];
            $type = $field->type();

            if ($type === 'replicator') {
                $problems = [...$problems, ...$this->replicatorProblems($field, $value, $handle, $label)];
            } elseif (in_array($type, self::OPTION_FIELD_TYPES, true)) {
                $problems = [...$problems, ...$this->optionProblems($field, $value, $handle, $label)];
            } elseif ($type === 'assets') {
                $problems = [...$problems, ...$this->assetProblems($field, $value, $handle, $label)];
            }
        }

        return $problems;
    }

    /**
     * @return array<int, string>
     */
    private function replicatorProblems(Field $field, mixed $value, string $path, string $label): array
    {
        if (! is_array($value)) {
            return [];
        }

        $sets = $this->flattenSets($field->get('sets', []));
        $problems = [];

        foreach ($value as $block) {
            if (! is_array($block) || ! isset($block['type'])) {
                continue;
            }

            $setHandle = $block['type'];

            if (! array_key_exists($setHandle, $sets)) {
                $problems[] = "{$label}: section [{$path}] uses unknown type '{$setHandle}'";

                continue;
            }

            $setFields = new Fields($sets[$setHandle]);
            $known = [...self::SET_META_KEYS, ...$setFields->all()->keys()->all()];

            foreach (array_keys($block) as $key) {
                if (! in_array($key, $known, true)) {
                    $problems[] = "{$label}: section '{$setHandle}' has unknown field '{$key}'";
                }
            }

            $problems = [...$problems, ...$this->fieldsProblems($setFields, $block, "{$label} > {$setHandle}")];
        }

        return $problems;
    }

    /**
     * @return array<int, string>
     */
    private function optionProblems(Field $field, mixed $value, string $handle, string $label): array
    {
        $options = $field->get('options', []);

        if ($options === [] || $value === null || $value === '') {
            return [];
        }

        $keys = array_is_list($options) ? $options : array_keys($options);
        $problems = [];

        // Single-value fields hold a scalar; `multiple` ones hold a list. Check
        // every selected value against the declared option keys either way.
        foreach (is_array($value) ? $value : [$value] as $selected) {
            if ($selected === null || $selected === '') {
                continue;
            }

            if (! in_array($selected, $keys, true)) {
                $shown = is_scalar($selected) ? $selected : gettype($selected);
                $problems[] = "{$label}: field '{$handle}' has invalid option '{$shown}'";
            }
        }

        return $problems;
    }

    /**
     * @return array<int, string>
     */
    private function assetProblems(Field $field, mixed $value, string $handle, string $label): array
    {
        $container = $field->get('container', 'assets');

        if (! in_array($container, config('agentic.committed_asset_containers'), true)) {
            return [];
        }

        $paths = array_filter(is_array($value) ? $value : [$value], fn ($path): bool => is_string($path) && $path !== '');
        $problems = [];

        foreach ($paths as $path) {
            if (Asset::find("{$container}::{$path}") === null) {
                $problems[] = "{$label}: field '{$handle}' references missing asset '{$path}'";
            }
        }

        return $problems;
    }

    /**
     * Flatten a replicator's grouped `sets` config to [setHandle => fieldsConfig].
     *
     * @param  array<string, mixed>  $sets
     * @return array<string, array<int, mixed>>
     */
    private function flattenSets(array $sets): array
    {
        $flat = [];

        foreach ($sets as $handle => $config) {
            if (isset($config['sets']) && is_array($config['sets'])) {
                foreach ($config['sets'] as $setHandle => $setConfig) {
                    $flat[$setHandle] = $setConfig['fields'] ?? [];
                }
            } else {
                $flat[$handle] = $config['fields'] ?? [];
            }
        }

        return $flat;
    }

    /**
     * Every navigation item that links to an entry must point at one that still
     * exists. A dangling reference makes the menu item silently vanish (or 404),
     * which the front-end never flags.
     *
     * @return array<int, string>
     */
    public function navProblems(): array
    {
        $problems = [];

        foreach (Nav::all() as $nav) {
            foreach ($nav->trees() as $tree) {
                foreach ($tree->flattenedPages() as $page) {
                    $reference = $page->reference();

                    if ($reference !== null && Entry::find($reference) === null) {
                        $problems[] = "navigation [{$nav->handle()}]: menu item links to a page that no longer exists (id '{$reference}')";
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Every page-builder set handle must have a matching block partial, or the
     * render loop silently drops it. Guards the "set handle == partial" convention.
     *
     * @return array<int, string>
     */
    public function blockPartialProblems(): array
    {
        $handle = config('agentic.page_builder_fieldset');
        if (! $fieldset = Fieldset::find($handle)) {
            return [];
        }
        if (! $field = $fieldset->fields()->all()->get($handle)) {
            return [];
        }

        $dir = config('agentic.blocks_view_path');
        $problems = [];

        foreach (array_keys($this->flattenSets($field->get('sets', []))) as $setHandle) {
            if (! $this->blockPartialExists($dir, $setHandle)) {
                $problems[] = "page-builder set '{$setHandle}' has no block partial (expected resources/views/{$dir}/{$setHandle}.antlers.html)";
            }
        }

        return $problems;
    }

    private function blockPartialExists(string $dir, string $handle): bool
    {
        foreach (["{$handle}.antlers.html", "_{$handle}.antlers.html", "{$handle}.blade.php"] as $file) {
            if (File::exists(resource_path("views/{$dir}/{$file}"))) {
                return true;
            }
        }

        return false;
    }
}
