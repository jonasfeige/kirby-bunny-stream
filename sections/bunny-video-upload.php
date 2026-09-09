<?php

declare(strict_types=1);

return [
    'props' => [
        'label' => function ($label = 'Upload Video') {
            return $label;
        },
        'help' => function ($help = null) {
            return $help;
        },
        'accept' => function ($accept = 'video/*') {
            return $accept;
        },
        'max' => function ($max = null) {
            return $max;
        },
        // Custom collection path (static string or Kirby query)
        // Examples: "my-collection", "{{ page.parent.id }}"
        'collection' => function ($collection = null) {
            return $collection;
        },
    ],
    'computed' => [
        'parentType' => function () {
            $parent = $this->model();
            if ($parent instanceof \Kirby\Cms\Site) {
                return 'site';
            }
            return 'page';
        },
        'parentId' => function () {
            $parent = $this->model();
            if ($parent instanceof \Kirby\Cms\Site) {
                return null;
            }
            return $parent->id();
        },
        'apiEndpoint' => function () {
            return 'bunny-stream';
        },
    ],
];
