<?php

return [
    // Fieldset the catalog + block-partial check read as the source of truth.
    'page_builder_fieldset' => 'page_builder',

    // resources/views/<blocks_view_path>/<set-handle>.antlers.html
    'blocks_view_path' => 'blocks',

    // Containers whose files are committed to the repo and can be existence-checked.
    'committed_asset_containers' => ['assets'],

    // Generated agent catalog.
    'catalog_path' => 'content/agent-reference.md',

    // Content-agent brain.
    'agents_file' => 'content/AGENTS.md',

    // Developer-facing editor onboarding prompt (stamped by agentic:setup).
    'onboarding_file' => 'ONBOARDING.md',

    // Git topology the agent + docs assume.
    'branches' => [
        'work' => 'staging',
        'release' => 'main',
    ],
];
