<?php

use App\User;
use Cartalyst\Sentry\Groups\Eloquent\Group;
use Cartalyst\Sentry\Users\UserExistsException;
use Illuminate\Foundation\Testing\WithoutMiddleware;


class SentryAuthenticationTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        // Wipe and replace the current sentinel sqlite file
        copy(database_path('sentry.sqlite'), database_path('database.sqlite'));

        // All of the tests in this class will use the original sqlite-sentry db
        config(['database.default' => 'sqlite']);
    }

    /** @test */
    public function users_exist_with_sentry()
    {
        // Verify that the seeded data we are working with is accessible
        $this->seeInDatabase('users', ['email' => 'admin@admin.com']);
        $this->seeInDatabase('users', ['email' => 'user@user.com']);
        $this->seeInDatabase('groups', ['name' => 'Admins']);
    }

    /** @test */
    public function a_user_can_authenticate_with_sentry()
    {
        // Verify that there isn't a currently active session
        $this->assertFalse(Sentry::check());

        // Authenticate a user via credentials
        Sentry::authenticate(['email' => 'user@user.com', 'password' => 'sentryuser'], false);

        // Verify there is now an active session
        $this->assertTrue(Sentry::check());
    }

    /** @test */
    public function a_user_can_be_logged_in_with_sentry()
    {
        // Verify that there isn't a currently active session
        $this->assertFalse(Sentry::check());

        // Fetch a user instance to work with
        $user = User::where('email', 'user@user.com')->first();

        // Attempt to login the user directly
        Sentry::login($user, false);

        // Verify there is now an active session
        $this->assertTrue(Sentry::check());
    }

    /** @test */
    public function a_user_can_log_out_with_sentry()
    {
        // Authenticate a user via credentials
        Sentry::authenticate(['email' => 'user@user.com', 'password' => 'sentryuser'], false);

        // Verify there is now an active session
        $this->assertTrue(Sentry::check());

        // Close the session
        Sentry::logout();

        // Verify that there isn't a currently active session
        $this->assertFalse(Sentry::check());
    }

    /** @test */
    public function a_user_can_register_with_sentry()
    {
        // Verify the user does not already exist
        $this->assertFalse(User::where('email', 'andrei@prozorov.net')->exists());

        // Register a new user
        $user = Sentry::register(array(
            'email'      => 'andrei@prozorov.net',
            'password'   => 'natasha'
        ));

        // Verify the user was created and not activated
        $this->seeInDatabase('users', ['email' => 'andrei@prozorov.net', 'activated' => 0]);
    }

    /** @test */
    public function a_user_can_register_and_be_activated_with_sentry()
    {
        // Verify the user does not already exist
        $this->assertFalse(User::where('email', 'andrei@prozorov.net')->exists());

        // Register a new user
        $user = Sentry::register(array(
            'email'      => 'andrei@prozorov.net',
            'password'   => 'natasha'
        ), true);

        // Verify the user was created and not activated
        $this->seeInDatabase('users', ['email' => 'andrei@prozorov.net', 'activated' => 1]);
    }

    /** @test */
    public function a_duplicate_user_cannot_register_with_sentry()
    {
        // This test should thow an exception
        $this->expectException(UserExistsException::class);

        // Register a new user
        $user = Sentry::register(array(
            'email'      => 'user@user.com',
            'password'   => 'password'
        ));
    }

    /** @test */
    public function a_user_can_activate_their_account_with_sentry()
    {
        // Register a new user
        $user = Sentry::register(array(
            'email'      => 'andrei@prozorov.net',
            'password'   => 'natasha'
        ));

        // Fetch the user object
        $user = User::where('email', 'andrei@prozorov.net')->first();
        $this->assertInstanceOf(User::class, $user);

        // Verify they are not currently activated
        $this->assertFalse($user->isActivated());

        // Retrieve the activation code
        $activationCode = $user->getActivationCode();

        // Attempt the activation
        $user->attemptActivation($activationCode);

        // Refresh the user object
        $user->fresh();

        // Did it work?
        $this->assertTrue($user->isActivated());
    }

    /** @test */
    public function a_user_can_reset_their_password_with_sentry()
    {
        // Find the user using the user email address
        $user = Sentry::findUserByLogin('user@user.com');

        // Get the password reset code
        $resetCode = $user->getResetPasswordCode();

        // Check if the reset password code is valid
        if ($user->checkResetPasswordCode($resetCode))
        {
            // Attempt to reset the user password
            if ($user->attemptResetPassword($resetCode, 'new_password'))
            {
                // Password reset passed
            }
        }

        // Verify that there isn't a currently active session
        $this->assertFalse(Sentry::check());

        // Authenticate a user via credentials
        Sentry::authenticate(['email' => 'user@user.com', 'password' => 'new_password'], false);

        // Verify there is now an active session
        $this->assertTrue(Sentry::check());
    }

    /** @test */
    public function user_permissions_with_sentry()
    {
        // Fetch a user to work with
        $user = User::where('email', 'user@user.com')->first();

        // Verify the lack of our test permisison
        $this->assertFalse($user->hasAccess('violin.play'));

        // Same thing, using a different method
        $this->assertFalse($user->hasPermission('violin.play'));

        // Set our test permission
        $user->permissions = ['violin.play' => 1];
        $user->invalidateMergedPermissionsCache();
        $user->save();

        // Verify the user has been granted our test permission
        $this->assertTrue($user->hasAccess('violin.play'));
    }

    /** @test */
    public function role_permissions_with_sentry()
    {
        // Fetch a user that is a member of a group
        $admin = User::where('email', 'admin@admin.com')->first();

        // Get the group object
        $group = Sentry::findGroupByName('Admins');
        $this->assertInstanceOf(Group::class, $group);

        // Verify group membership
        $this->assertTrue($admin->inGroup($group));

        // Test for permision granted by group membership
        $this->assertTrue($admin->hasAccess('admin'));
    }

    /** @test */
    public function a_user_can_be_added_to_a_role_with_sentry()
    {
        // Create a new group with a test permission
        $group = Sentry::createGroup(array(
            'name'        => 'Prozorov',
            'permissions' => array(
                'family' => 1,
                'regiment' => 0
            ),
        ));

        // Fetch a user to work with
        $user = User::where('email', 'user@user.com')->first();

        // Verify lack of group membership
        $this->assertFalse($user->inGroup($group));

        // Add the user to the group
        $user->addGroup($group);

        // Verify new group membership
        $this->assertTrue($user->inGroup($group));

        // Verify permissions inhereted from group membership
        $this->assertTrue($user->hasAccess('family'));
    }

    /** @test */
    public function a_user_can_be_removed_from_a_role_with_sentry()
    {
        // Fetch a user to work with
        $admin = User::where('email', 'admin@admin.com')->first();

        // Fetch group
        $group = Sentry::findGroupByName('Admins');

        // Verify existing group membership
        $this->assertTrue($admin->inGroup($group));

        // Remove the user from the group
        $admin->removeGroup($group);

        // Verify that the membership has been revoked
        $this->assertFalse($admin->inGroup($group));
    }
}
