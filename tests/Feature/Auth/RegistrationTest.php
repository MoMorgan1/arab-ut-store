<?php

use App\Models\User;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/my-account');
    $this->assertDatabaseHas('users', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
    ]);
});

test('Arabic registration returns every password requirement in Arabic', function (string $password, string $message) {
    Password::defaults(fn (): Password => Password::min(12)
        ->mixedCase()
        ->letters()
        ->numbers()
        ->symbols());

    try {
        $this->post(route('register.store'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => md5($password).'@example.com',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasErrors([
            'password' => $message,
        ]);
    } finally {
        Password::defaults(fn (): null => null);
    }
})->with([
    'mixed case' => [
        'strongpassword12!',
        'يجب أن تحتوي كلمة المرور على حرف إنجليزي كبير وحرف صغير على الأقل.',
    ],
    'number' => [
        'StrongPassword!',
        'يجب أن تحتوي كلمة المرور على رقم واحد على الأقل.',
    ],
    'symbol' => [
        'StrongPassword12',
        'يجب أن تحتوي كلمة المرور على رمز واحد على الأقل.',
    ],
]);

test('users without local passwords can be persisted for imported identities', function () {
    $user = User::create([
        'first_name' => 'Imported',
        'last_name' => 'Customer',
        'email' => 'imported@example.com',
        'password' => null,
    ]);

    expect($user->password)->toBeNull()
        ->and($user->name)->toBe('Imported Customer')
        ->and($user->toArray())->toMatchArray([
            'first_name' => 'Imported',
            'last_name' => 'Customer',
            'name' => 'Imported Customer',
        ]);
});
