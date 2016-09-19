<?php

use App\AlternateUser as User;
use Cartalyst\Sentinel\Roles\EloquentRole;
use Cartalyst\Sentry\Groups\Eloquent\Group;
use Cartalyst\Sentry\Users\UserExistsException;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Cartalyst\Sentinel\Activations\EloquentActivation;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class SentinelAuthenticationTest extends TestCase
{

    public function setUp()
    {
        parent::setUp();

        // Wipe and replace the current sentinel sqlite file
        copy(database_path('sentinel.sqlite'), database_path('database.sqlite'));

        // All of the tests in this class will use the original sqlite-sentry db
        config(['database.default' => 'sqlite']);
    }

    /** @test */
    public function users_exist_with_sentinel()
    {
        // Verify that the seeded data we are working with is accessible
        $this->seeInDatabase('users', ['email' => 'admin@admin.com']);
        $this->seeInDatabase('users', ['email' => 'user@user.com']);
        $this->seeInDatabase('roles', ['name' => 'Admins']);

        // Verify that we are using the correct sqlite file
        $this->assertTrue(Schema::hasTable('persistences'));
    }

    /** @test */
    public function a_user_can_authenticate_with_sentinel()
    {
        // Verify that there isn't a currently active session
        $this->assertFalse(Sentinel::check());

        // Authenticate a user via credentials
        Sentinel::authenticate(['email' => 'user@user.com', 'password' => 'sentryuser'], false);

        // Verify there is now an active session
        $this->assertInstanceOf(User::class, Sentinel::check());
    }

    /** @test */
    public function a_user_can_be_logged_in_with_sentinel()
    {
        // Verify that there isn't a currently active session
        $this->assertFalse(Sentinel::check());

        // Fetch a user instance to work with
        $user = User::where('email', 'user@user.com')->first();

        // Attempt to login the user directly
        Sentinel::login($user, false);

        // Verify there is now an active session
        $this->assertInstanceOf(User::class, Sentinel::check());
    }

    /** @test */
    public function a_user_can_log_out_with_sentinel()
    {
        // Authenticate a user via credentials
        Sentinel::authenticate(['email' => 'user@user.com', 'password' => 'sentryuser'], false);

        // Verify there is now an active session
        $this->assertInstanceOf(User::class, Sentinel::check());

        // Close the session
        Sentinel::logout();

        // Verify that there isn't a currently active session
        $this->assertFalse(Sentinel::check());
    }

    /** @test */
    public function a_user_can_register_with_sentinel()
    {
        // Verify the user does not already exist
        $this->assertFalse(User::where('email', 'andrei@prozorov.net')->exists());

        // Register a new user
        $user = Sentinel::register(array(
            'email'      => 'andrei@prozorov.net',
            'password'   => 'natasha'
        ));

        // Verify the user was created and not activated
        $this->seeInDatabase('users', ['email' => 'andrei@prozorov.net']);
        $this->dontSeeInDatabase('activations', ['user_id' => $user->id]);
    }

    /** @test */
    public function a_user_can_register_and_be_activated_with_sentinel()
    {
        $credentials = [
            'email'      => 'andrei@prozorov.net',
            'password'   => 'natasha'
        ];

        // Verify the user does not already exist
        $this->assertNull(Sentinel::findUserByCredentials($credentials));

        // Register a new user
        $user = Sentinel::register($credentials, true);

        // Verify the user was created and not activated
        $this->seeInDatabase('users', ['email' => 'andrei@prozorov.net']);
        $this->seeInDatabase('activations', ['user_id' => $user->id]);
    }

    /** @test */
    public function a_duplicate_user_cannot_register_with_sentinel()
    {
        $credentials = [
            'email'      => 'user@user.com',
            'password'   => 'sentryuser'
        ];

        if (!Sentinel::findUserByCredentials($credentials)) {
            $user = Sentinel::register($credentials);
        }

        $this->assertEquals(1, User::where('email', 'user@user.com')->count());
    }

    /** @test */
    public function a_user_can_activate_their_account_with_sentinel()
    {
        // Register a new user
        $user = Sentinel::register(array(
            'email'      => 'andrei@prozorov.net',
            'password'   => 'natasha'
        ));

        // Fetch the user object
        $user = User::where('email', 'andrei@prozorov.net')->first();
        $this->assertInstanceOf(User::class, $user);

        // Verify they are not currently activated
        $this->assertFalse(Activation::exists($user));
        $this->assertFalse(Activation::completed($user));

        // Retrieve the activation code
        $activation = Activation::create($user);

        // Attempt the activation
        Activation::complete($user, $activation->code);

        // Did it work?
        $this->assertInstanceOf(EloquentActivation::class,
            Activation::completed($user));
    }

    /** @test */
    public function a_user_can_reset_their_password_with_sentinel()
    {
        // Find the user using the user email address
        $user = Sentinel::findUserByCredentials(['email' => 'user@user.com']);

        // Get the password reset code
        $reminder = Reminder::create($user);

        // Attempt to reset the user password
        Reminder::complete($user, $reminder->code, 'new_password');

        // Verify that there isn't a currently active session
        $this->assertFalse(Sentinel::check());

        // Authenticate a user via credentials
        Sentinel::authenticate(['email' => 'user@user.com', 'password' => 'new_password']);

        // Verify there is now an active session
        $this->assertInstanceOf(User::class, Sentinel::check());
    }

    /** @test */
    public function user_permissions_with_sentinel()
    {
        // Fetch a user to work with
        $user = User::where('email', 'user@user.com')->first();

        // Set our test permission
        $user->addPermission('violin.play');
        $user->save();

        // Verify the user has been granted our test permission
        $this->assertTrue($user->hasAccess('violin.play'));
    }

    /** @test */
    public function role_permissions_with_sentinel()
    {
        // Fetch a user that is a member of a role
        $admin = User::where('email', 'admin@admin.com')->first();

        // Get the role object
        $role = Sentinel::findRoleByName('Admins');
        $this->assertInstanceOf(EloquentRole::class, $role);

        // Verify role membership
        $this->assertTrue($admin->inRole($role->slug));

        // Test for permision granted by role membership
        $this->assertTrue($admin->hasAccess('admin'));
    }

    /** @test */
    public function a_user_can_be_added_to_a_role_with_sentinel()
    {
        // Create a new role with a test permission
        $role = Sentinel::getRoleRepository()->createModel()->create(array(
            'name' => 'Prozorov',
            'slug' => 'prozorov',
            'permissions' => array(
                'family' => true,
                'regiment' => false
            ),
        ));

        // Fetch a user to work with
        $user = User::where('email', 'user@user.com')->first();

        // Verify lack of role membership
        $this->assertFalse($user->inRole($role->slug));

        // Add the user to the role
        $role->users()->attach($user->id);
        $user->load('roles');

        // Verify new role membership
        $this->assertTrue($user->inRole($role->slug));

        // Verify permissions inhereted from role membership
        $this->assertTrue($user->hasAccess('family'));
    }

    /** @test */
    public function a_user_can_be_removed_from_a_role_with_sentinel()
    {
        // Fetch a user to work with
        $admin = User::where('email', 'admin@admin.com')->first();

        // Fetch role
        $role = Sentinel::findRoleByName('Admins');

        // Verify existing role membership
        $this->assertTrue($admin->inRole($role->slug));

        // Remove the user from the role
        $role->users()->detach($admin);
        $admin->load('roles');

        // Verify that the membership has been revoked
        $this->assertFalse($admin->inRole($role->slug));
    }
}
