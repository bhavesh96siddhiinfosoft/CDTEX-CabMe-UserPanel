@extends('errors.layout', [
    'code' => '404',
    'icon' => 'pin',
    'title' => __('We could not find that page'),
    'message' => __('The link may be old, or the page may have moved.'),
])
