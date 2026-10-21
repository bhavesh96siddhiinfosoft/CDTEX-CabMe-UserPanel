@extends('errors.layout', [
    'code' => '500',
    'icon' => 'car',
    'title' => __('Something went wrong at our end'),
    'message' => __('Nothing you did — please try again in a moment.'),
])
