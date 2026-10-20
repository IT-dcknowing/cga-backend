<?php

namespace Tests\Tooling\PHPStan\data;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

class ExempleDonneesDeRequete
{
    public function interdit(Request $request, FormRequest $form): void
    {
        $request->all();
        $request->input('organization_id');
        $request->only(['status']);
        $form->all();
        $form->integer('amount');
    }

    public function autorise(FormRequest $form, Request $request): void
    {
        $form->validated();
        $form->safe()->only(['name']);
        $request->ip();
        $request->header('X-Request-Id');
        $request->user();
    }
}
