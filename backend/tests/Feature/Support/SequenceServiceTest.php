<?php

use App\Domain\Organizations\Models\Organization;
use App\Support\Numbering\SequenceService;
use Illuminate\Support\Facades\DB;

/*
 * Numérotations sans doublon ni trou (02-TRD §7.1, RG-ADH-03).
 */

function sequences(): SequenceService
{
    return app(SequenceService::class);
}

it('attribue des numéros consécutifs', function () {
    $cga = Organization::factory()->create();

    $numbers = DB::transaction(fn () => [sequences()->next($cga->id, 'membership'), sequences()->next($cga->id, 'membership'), sequences()->next($cga->id, 'membership')]);

    expect($numbers)->toBe([1, 2, 3])
        ->and(sequences()->current($cga->id, 'membership'))->toBe(3);
});

it('tient un compteur par organisation, par clé et par année, la séquence continue à part', function () {
    $cga = Organization::factory()->create();
    $other = Organization::factory()->create();

    DB::transaction(function () use ($cga): void {
        sequences()->next($cga->id, 'receipt', 2026);
        sequences()->next($cga->id, 'receipt', 2026);
        sequences()->next($cga->id, 'membership');
    });

    expect(DB::transaction(fn () => sequences()->next($cga->id, 'receipt', 2027)))->toBe(1)
        ->and(DB::transaction(fn () => sequences()->next($other->id, 'receipt', 2026)))->toBe(1)
        ->and(DB::transaction(fn () => sequences()->next($cga->id, 'membership')))->toBe(2)
        ->and(sequences()->current($cga->id, 'receipt', 2026))->toBe(2);
});

it('rend le numéro si l\'Action est annulée : aucun trou', function () {
    $cga = Organization::factory()->create();

    expect(fn () => DB::transaction(function () use ($cga): void {
        sequences()->next($cga->id, 'membership');
        throw new RuntimeException('Action annulée');
    }))->toThrow(RuntimeException::class);

    expect(DB::transaction(fn () => sequences()->next($cga->id, 'membership')))->toBe(1);
});

it('part de zéro pour une séquence qui n\'a pas commencé', function () {
    expect(sequences()->current(Organization::factory()->create()->id, 'membership'))->toBe(0);
});

it('refuse d\'attribuer un numéro hors de la transaction de l\'Action', function () {
    DB::partialMock()->shouldReceive('transactionLevel')->andReturn(0);

    sequences()->next(Organization::factory()->make()->id ?? 'x', 'membership');
})->throws(LogicException::class);

it('refuse une clé de séquence mal formée', function () {
    DB::transaction(fn () => sequences()->next(Organization::factory()->create()->id, 'membership; drop table sequences'));
})->throws(InvalidArgumentException::class);
