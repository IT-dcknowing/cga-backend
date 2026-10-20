<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/*
 * Fuseau Africa/Abidjan et langue française (06-IMPLEMENTATION-PLAN étape 0.3, 02-TRD §7.2).
 */

it('vit au fuseau Africa/Abidjan', function () {
    expect(config('app.timezone'))->toBe('Africa/Abidjan')
        ->and(now()->getTimezone()->getName())->toBe('Africa/Abidjan')
        ->and(date_default_timezone_get())->toBe('Africa/Abidjan');
});

it('parle français', function () {
    expect(app()->getLocale())->toBe('fr')
        ->and(config('app.faker_locale'))->toBe('fr_FR');
});

it('renvoie les messages de validation en français', function () {
    $validator = Validator::make(['telephone' => ''], ['telephone' => 'required']);

    expect($validator->errors()->first('telephone'))->toBe('Le champ telephone est obligatoire.');
});

it('traduit en français chaque message fourni par le framework', function (string $file) {
    $english = require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$file}.php");
    $french = require lang_path("fr/{$file}.php");

    $missing = array_diff(array_keys(Arr::dot($english)), array_keys(Arr::dot($french)));
    $ignored = ['custom.attribute-name.rule-name', 'attributes'];

    expect(array_values(array_diff($missing, $ignored)))->toBe([]);
})->with(['auth', 'pagination', 'passwords', 'validation']);
