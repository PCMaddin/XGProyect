<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Most templating systems load templates from disk. Here you may specify
    | an array of paths that should be checked for your views. Of course
    | the usual Laravel view path has already been registered for you.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | This option determines where all the compiled Blade templates will be
    | stored for your application. Typically, this is within the storage
    | directory. However, as usual, you are free to change this value.
    |
    */

    'compiled' => (static function (): string {
        $configured = env('VIEW_COMPILED_PATH');
        $own = base_path('bootstrap/views');

        // the Docker image compiles the views into bootstrap/views and names
        // that path; a version copied beside the running one (xgp:versions:sync)
        // has its own compiled views and must never use another version's
        if (is_string($configured) && $configured !== '' && (str_starts_with($configured, base_path()) || !is_dir($own))) {
            return $configured;
        }

        return is_dir($own) ? $own : (string) realpath(storage_path('framework/views'));
    })(),

];
