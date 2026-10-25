<?php

declare(strict_types=1);

use AlfaSix\AlfakitForms\Models\FormSubmission;
use AlfaSix\AlfakitForms\Support\Conditions;
use AlfaSix\AlfakitForms\Support\FormDefinition;

use function AlfaSix\AlfakitForms\Tests\block;
use function AlfaSix\AlfakitForms\Tests\contactForm;
use function AlfaSix\AlfakitForms\Tests\fieldNames;
use function AlfaSix\AlfakitForms\Tests\makeForm;
use function AlfaSix\AlfakitForms\Tests\publicForm;
use function AlfaSix\AlfakitForms\Tests\submitForm;
use function Pest\Laravel\postJson;

uses(AlfaSix\AlfakitForms\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

it('does not validate or store a hidden field, even when the browser sends it', function (): void {
    $form = contactForm();

    submitForm(publicForm($form->slug)['token'], [
        'your_name' => 'Ann',
        'email' => 'ann@example.com',
        'topic' => 'Sales',
        'order' => 'not digits at all',
    ])->assertRedirect();

    expect(FormSubmission::query()->sole()->data)->not->toHaveKey('order');
});

it('validates a conditional field once its rule matches', function (): void {
    $form = contactForm();
    $token = publicForm($form->slug)['token'];

    submitForm($token, ['your_name' => 'Ann', 'email' => 'ann@example.com', 'topic' => 'Support'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order' => 'required']);

    submitForm($token, ['your_name' => 'Ann', 'email' => 'ann@example.com', 'topic' => 'Support', 'order' => 'abc'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order']);

    submitForm($token, ['your_name' => 'Ann', 'email' => 'ann@example.com', 'topic' => 'Support', 'order' => '4711'])->assertRedirect();

    expect(FormSubmission::query()->sole()->data['order'])->toBe('4711');
});

it('shows a conditional field when the server re-renders the schema for new answers', function (): void {
    $form = contactForm();
    $token = publicForm($form->slug)['token'];

    $hidden = postJson('/_alfakit/schema/refresh', ['token' => $token, 'component' => 'form', 'state' => ['topic' => 'Sales']])->assertOk()->json();
    $shown = postJson('/_alfakit/schema/refresh', ['token' => $token, 'component' => 'form', 'state' => ['topic' => 'Support']])->assertOk()->json();

    expect(fieldNames($hidden['components']))->not->toContain('order')
        ->and(fieldNames($shown['components']))->toContain('order');
});

it('hides the fields of a section whose heading rule does not match', function (): void {
    $form = makeForm([
        block('radio', ['label' => 'Are you a company?', 'name' => 'company', 'options' => ['Yes', 'No'], 'required' => true]),
        block('heading', ['label' => 'Company details', 'conditions' => [['field' => 'company', 'operator' => 'equals', 'value' => 'yes']]]),
        block('text', ['label' => 'Company name', 'name' => 'company_name', 'required' => true]),
        block('text', ['label' => 'VAT', 'name' => 'vat']),
        block('heading', ['label' => 'About you']),
        block('text', ['label' => 'Your name', 'name' => 'person', 'required' => true]),
    ]);
    $token = publicForm($form->slug)['token'];

    submitForm($token, ['company' => 'No', 'person' => 'Ann', 'company_name' => 'Ignored'])->assertRedirect();

    expect(FormSubmission::query()->sole()->data)->toBe(['company' => 'No', 'person' => 'Ann']);

    submitForm($token, ['company' => 'Yes', 'person' => 'Ann'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['company_name']);
});

it('treats an answer of a hidden field as blank in later rules', function (): void {
    $definition = new FormDefinition([
        block('toggle', ['label' => 'Newsletter', 'name' => 'newsletter']),
        block('email', ['label' => 'Email', 'name' => 'email', 'conditions' => [['field' => 'newsletter', 'operator' => 'equals', 'value' => 'true']]]),
        block('text', ['label' => 'Confirm', 'name' => 'confirm', 'conditions' => [['field' => 'email', 'operator' => 'filled', 'value' => null]]]),
    ]);

    expect($definition->getVisibility(['newsletter' => true, 'email' => 'a@b.c']))->toBe(['field:newsletter' => true, 'field:email' => true, 'field:confirm' => true])
        ->and($definition->getVisibility(['newsletter' => false, 'email' => 'a@b.c']))->toBe(['field:newsletter' => true, 'field:email' => false, 'field:confirm' => false])
        ->and($definition->filterAnswers(['newsletter' => false, 'email' => 'a@b.c', 'confirm' => 'x', 'evil' => 1]))->toBe(['newsletter' => false]);
});

it('matches all or any of the rules', function (): void {
    $definition = new FormDefinition([
        block('number', ['label' => 'Age', 'name' => 'age']),
        block('select', ['label' => 'Country', 'name' => 'country', 'options' => ['BH', 'DE']]),
        block('text', ['label' => 'All', 'name' => 'all', 'conditions' => [
            ['field' => 'age', 'operator' => 'greater_than', 'value' => '17'],
            ['field' => 'country', 'operator' => 'equals', 'value' => 'BH'],
        ]]),
        block('text', ['label' => 'Any', 'name' => 'any', 'condition_match' => 'any', 'conditions' => [
            ['field' => 'age', 'operator' => 'less_than', 'value' => '18'],
            ['field' => 'country', 'operator' => 'equals', 'value' => 'DE'],
        ]]),
    ]);

    expect($definition->getVisibility(['age' => 30, 'country' => 'BH']))->toMatchArray(['field:all' => true, 'field:any' => false])
        ->and($definition->getVisibility(['age' => 12, 'country' => 'BH']))->toMatchArray(['field:all' => false, 'field:any' => true])
        ->and($definition->getVisibility(['age' => 30, 'country' => 'DE']))->toMatchArray(['field:all' => false, 'field:any' => true]);
});

it('evaluates every operator on single and multiple answers', function (mixed $answer, string $operator, ?string $value, bool $expected): void {
    expect(Conditions::check($answer, $operator, $value))->toBe($expected);
})->with([
    'equals is case-insensitive' => ['Support', 'equals', 'support', true],
    'equals on a list' => [['A', 'B'], 'equals', 'B', true],
    'not equals' => ['Sales', 'not_equals', 'Support', true],
    'contains text' => ['Hello world', 'contains', 'WORLD', true],
    'contains nothing' => ['Hello', 'contains', '', false],
    'not contains' => [['A'], 'not_contains', 'b', true],
    'filled' => ['x', 'filled', null, true],
    'filled list' => [[], 'filled', null, false],
    'blank' => [null, 'blank', null, true],
    'unticked is blank' => [false, 'equals', 'no', true],
    'greater than' => ['10', 'greater_than', '9', true],
    'less than date' => ['2026-01-01', 'less_than', '2026-02-01', true],
    'incomparable' => ['abc', 'greater_than', '1', false],
    'unknown operator' => ['x', 'regex', '.*', false],
]);

it('normalises builder state: answer keys, options and rules', function (): void {
    $items = FormDefinition::normalize([
        'uuid-1' => block('text', ['label' => 'Full name']),
        'uuid-2' => block('text', ['label' => 'Full name']),
        'uuid-3' => block('email', ['label' => 'E-mail', 'name' => 'Bad Name!']),
        'uuid-4' => block('select', ['label' => 'Topic', 'options' => [' A ', 'A', '', ['option' => 'B'], ['nested' => ['x']]]]),
        'uuid-5' => block('text', ['label' => '123', 'conditions' => [
            ['field' => 'full_name', 'operator' => 'equals', 'value' => 'x'],
            ['field' => 'missing', 'operator' => 'equals', 'value' => 'x'],
            ['field' => 'full_name', 'operator' => 'regex', 'value' => '/(a+)+$/'],
            ['field' => 'field_123', 'operator' => 'filled', 'value' => 'ignored'],
        ]]),
        'uuid-6' => block('unknown', ['label' => 'Dropped']),
        'uuid-7' => block('text', ['label' => 'Captcha']),
    ]);

    expect(array_column(array_column($items, 'data'), 'name'))->toBe(['full_name', 'full_name_2', 'bad_name', 'topic', 'field_123', 'captcha_2'])
        ->and($items[3]['data']['options'])->toBe(['A', 'B'])
        ->and($items[4]['data']['conditions'])->toBe([['field' => 'full_name', 'operator' => 'equals', 'value' => 'x']])
        ->and(array_column($items, 'type'))->not->toContain('unknown');
});

it('asks for a value on a new rule, whose operator is still the default', function (): void {
    expect(Conditions::needsValue(null))->toBeTrue()
        ->and(Conditions::needsValue('equals'))->toBeTrue()
        ->and(Conditions::needsValue('filled'))->toBeFalse();
});
