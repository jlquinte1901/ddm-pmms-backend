<?php

return [
    'pagination' => [
        'default' => (int) env('API_PAGINATION_DEFAULT', 15),
        'max' => (int) env('API_PAGINATION_MAX', 100),
    ],
];
