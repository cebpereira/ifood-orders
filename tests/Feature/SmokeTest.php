<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;

it('responds on the health check endpoint', function (): void {
    $this->get('/up')->assertOk();
});

it('renders the orders panel', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('orders/index'));
});
