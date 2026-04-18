<?php

return [
    'encoding' => 'UTF-8',
    'finalize' => true,
    'ignoreNonStrings' => false,
    'cachePath' => storage_path('app/purifier'),
    'cacheFileMode' => 0755,

    'settings' => [
        'default' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => 'p,b,strong,i,em,a[href|title],ul,ol,li,br,hr,blockquote,code,pre,h1,h2,h3,h4,h5,h6',
            'HTML.ForbiddenElements' => 'script,style,iframe,object,embed,form,input',
            'CSS.AllowedProperties' => '',
            'AutoFormat.AutoParagraph' => false,
            'AutoFormat.RemoveEmpty' => true,
            'URI.AllowedSchemes' => [
                'http' => true,
                'https' => true,
                'mailto' => true,
            ],
        ],

        // User-generated content – used inside reports.
        'meldeplattform' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => 'b,strong,i,em,br,p,ul,ol,li,a[href],code,pre,blockquote',
            'Attr.AllowedFrameTargets' => [],
            'URI.AllowedSchemes' => [
                'http' => true,
                'https' => true,
                'mailto' => true,
            ],
            'AutoFormat.AutoParagraph' => false,
            'AutoFormat.RemoveEmpty' => true,
        ],

        // Operator content (imprint, privacy) – slightly wider tag set.
        'operator' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => 'p,b,strong,i,em,a[href|title],ul,ol,li,br,hr,blockquote,code,pre,h1,h2,h3,h4,h5,h6,table,thead,tbody,tr,td,th',
            'URI.AllowedSchemes' => [
                'http' => true,
                'https' => true,
                'mailto' => true,
            ],
        ],
    ],
];
