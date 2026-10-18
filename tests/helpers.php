<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Tests;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitForms\Models\Form;
use AlfaSix\AlfakitForms\Tests\Fixtures\Models\FormsUser;
use Illuminate\Testing\TestResponse;

/** @return array<string, string> */
function inertiaHeaders(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => ''];
}

/**
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function inertiaGet(string $url): TestResponse
{
    return \Pest\Laravel\get($url, inertiaHeaders());
}

/**
 * Post an action as the renderer's action runner does.
 *
 * @param  array<string, mixed>  $data
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function inertiaPost(string $url, array $data): TestResponse
{
    return \Pest\Laravel\post($url, $data, [...inertiaHeaders(), 'Accept' => 'application/json']);
}

/**
 * The public form payload of /forms/{slug}.
 *
 * @return array<string, mixed>
 */
function publicForm(string $slug): array
{
    $response = inertiaGet('/forms/'.$slug);
    $response->assertOk();

    return $response->json('props.form');
}

/**
 * Submit the public form with these answers.
 *
 * @param  array<string, mixed>  $data
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function submitForm(string $token, array $data): TestResponse
{
    return inertiaPost('/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => $data]);
}

function user(string $name = 'Ada Admin'): FormsUser
{
    return FormsUser::query()->create(['name' => $name, 'email' => uniqid('user', true).'@example.com', 'password' => 'password']);
}

function team(string $name): Team
{
    return Team::query()->create(['name' => $name, 'slug' => strtolower($name)]);
}

/**
 * A builder block.
 *
 * @param  array<string, mixed>  $data
 * @return array{type: string, data: array<string, mixed>}
 */
function block(string $type, array $data = []): array
{
    return ['type' => $type, 'data' => $data];
}

/**
 * A published form.
 *
 * @param  array<int, array{type: string, data: array<string, mixed>}>  $fields
 * @param  array<string, mixed>  $attributes
 */
function makeForm(array $fields, array $attributes = []): Form
{
    return Form::query()->create([
        'title' => 'Contact us',
        'slug' => 'contact-'.uniqid(),
        'fields' => $fields,
        'is_published' => true,
        ...$attributes,
    ]);
}

/**
 * The contact form most tests use: name (required), email (required), topic (select)
 * and an order number shown only for support questions.
 */
function contactForm(array $attributes = []): Form
{
    return makeForm([
        block('text', ['label' => 'Your name', 'required' => true, 'max_length' => 50]),
        block('email', ['label' => 'Email', 'name' => 'email', 'required' => true]),
        block('select', ['label' => 'Topic', 'name' => 'topic', 'options' => ['Sales', 'Support'], 'required' => true]),
        block('text', [
            'label' => 'Order number',
            'name' => 'order',
            'required' => true,
            'format' => 'digits',
            'conditions' => [['field' => 'topic', 'operator' => 'equals', 'value' => 'Support']],
        ]),
        block('textarea', ['label' => 'Message', 'name' => 'message']),
    ], $attributes);
}

/**
 * Every component in a serialised schema tree, depth-first.
 *
 * @param  array<int|string, mixed>  $nodes
 * @return array<int, array<string, mixed>>
 */
function flatten(array $nodes): array
{
    $flat = [];

    foreach ($nodes as $node) {
        if (! is_array($node)) {
            continue;
        }

        if (isset($node['type']) && is_string($node['type'])) {
            $flat[] = $node;
        }

        foreach (['components', 'schema', 'children', 'items', 'actions', 'content', 'steps', 'tabs', 'widgets'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                array_push($flat, ...flatten(array_is_list($node[$key]) ? $node[$key] : [$node[$key]]));
            }
        }
    }

    return $flat;
}

/**
 * The state paths of the fields in a serialised schema.
 *
 * @param  array<int|string, mixed>  $nodes
 * @return array<int, string>
 */
function fieldNames(array $nodes): array
{
    return array_values(array_filter(array_map(static fn (array $node): ?string => is_string($node['statePath'] ?? null) ? $node['statePath'] : null, flatten($nodes))));
}
