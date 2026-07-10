<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Term;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

#[Signature('content:validate')]
#[Description('Validate all flat-file content against its blueprints. Exits non-zero on any schema violation.')]
class ValidateContent extends Command
{
    private const SET_META_KEYS = ['id', 'type', 'enabled'];

    private const OPTION_FIELD_TYPES = ['select', 'radio', 'button_group'];

    public function handle(): int
    {
        $problems = $this->collectProblems();
        $problems = [...$problems, ...$this->blockPartialProblems()];

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
            $problems = [...$problems, ...$this->fieldsProblems(
                $blueprint->fields(),
                $entry->data()->all(),
                "entry [{$entry->id()}] ({$entry->locale()})"
            )];
        }

        foreach (Term::all() as $term) {
            if (! $blueprint = $term->blueprint()) {
                continue;
            }
            $problems = [...$problems, ...$this->fieldsProblems(
                $blueprint->fields(),
                $term->data()->all(),
                "term [{$term->id()}]"
            )];
        }

        foreach (GlobalSet::all() as $set) {
            if (! $blueprint = $set->blueprint()) {
                continue;
            }
            foreach ($set->localizations() as $locale => $localization) {
                $problems = [...$problems, ...$this->fieldsProblems(
                    $blueprint->fields(),
                    $localization->data()->all(),
                    "global [{$set->handle()}] ({$locale})"
                )];
            }
        }

        return $problems;
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

        if ($options === [] || $field->get('multiple') || $value === null || $value === '' || is_array($value)) {
            return [];
        }

        $keys = array_is_list($options) ? $options : array_keys($options);

        if (in_array($value, $keys, true)) {
            return [];
        }

        return ["{$label}: field '{$handle}' has invalid option '{$value}'"];
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
