<?php

declare(strict_types=1);

use Ultra\Mapper\Path;

covers(Path::class);

it('can add variable to array string', function (): void {
    $path = Path::new()
        ->add('foo')
        ->add('bar')
        ->add(42)
    ;

    expect($path->toArrayString(['$k']))->toBe("['foo', 'bar', 42, \$k]");
});
