<?php

declare(strict_types=1);

use Waypost\Support\Table;
use Waypost\Tests\Fixtures\Widget;
use Waypost\Tests\Fixtures\WidgetTag;

it('prefixes table names with waypost_ by default', function (): void {
    expect(Table::prefix())->toBe('waypost_')
        ->and(Table::name('users'))->toBe('waypost_users');
});

it('uses the configured prefix', function (): void {
    config(['waypost.table_prefix' => 'wp_']);

    expect(Table::name('users'))->toBe('wp_users')
        ->and((new Widget())->getTable())->toBe('wp_widgets');
});

it('allows an empty prefix', function (): void {
    config(['waypost.table_prefix' => '']);

    expect(Table::name('users'))->toBe('users');
});

it('prefixes the default table name of a model', function (): void {
    expect((new Widget())->getTable())->toBe('waypost_widgets');
});

it('prefixes an explicit table name of a model', function (): void {
    expect((new WidgetTag())->getTable())->toBe('waypost_widget_tag');
});

it('does not prefix twice when Eloquent copies the table name', function (): void {
    $widget = new Widget(['id' => '019a2a6e-0000-7000-8000-000000000000']);

    expect($widget->newInstance()->getTable())->toBe('waypost_widgets')
        ->and($widget->replicate()->getTable())->toBe('waypost_widgets')
        ->and($widget->newQuery()->getModel()->getTable())->toBe('waypost_widgets')
        ->and($widget->newQuery()->toBase()->from)->toBe('waypost_widgets');
});
