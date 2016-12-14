<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cartalyst\Sentinel\Users\EloquentUser;
use Illuminate\Database\Migrations\Migration;

class AddSentinelSchema extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Mostly copied directly from the original migration:
        // https://github.com/cartalyst/sentinel/blob/v2.0.9/src/migrations/2014_07_02_230147_migration_cartalyst_sentinel.php

        // Activations
        Schema::create('activations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned();
            $table->string('code');
            $table->boolean('completed')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->engine = 'InnoDB';
        });

        // Persistences
        Schema::create('persistences', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned();
            $table->string('code');
            $table->timestamps();

            $table->engine = 'InnoDB';
            $table->unique('code');
        });

        // Reminders
        Schema::create('reminders', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned();
            $table->string('code');
            $table->boolean('completed')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // Roles
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug');
            $table->string('name');
            $table->text('permissions')->nullable();
            $table->timestamps();

            $table->engine = 'InnoDB';
            $table->unique('slug');
        });

        // Role/User Pivot Table
        Schema::create('role_users', function (Blueprint $table) {
            $table->integer('user_id')->unsigned();
            $table->integer('role_id')->unsigned();
            $table->nullableTimestamps();

            $table->engine = 'InnoDB';
            $table->primary(['user_id', 'role_id']);
        });

        // Modify the existing throttle table
        Schema::table('throttle', function (Blueprint $table) {
            // Make the user_id column nullable.
            $table->integer('user_id')->unsigned()->nullable()->change();
            $table->string('type')->nullable();
            $table->string('ip')->nullable();
            $table->timestamps();
        });

        // Convert user table data as appropriate
        $users = EloquentUser::get();
        foreach ($users as $user) {

            // Create an activation record
            if ($user->activated) {
                $activation = Activation::create($user);
                Activation::complete($user, $activation->code);
                $activation->completed_at = $user->activated_at;
                $activation->save();
            }

            // Convert the user specific permissions
            $user->permissions = $this->convertPermissions($user->permissions);
            $user->save();
        }

        // Convert the groups into roles
        $groups = DB::table('groups')->get();
        $groupReference = [];
        foreach($groups as $group) {

            // Create the role
            $role = Sentinel::getRoleRepository()->createModel()->create([
                'name' => $group->name,
                'slug' => str_slug($group->name),
            ]);

            // Convert the permissons
            $permissions = json_decode($group->permissions);
            $role->permissions = $this->convertPermissions($permissions);
            $role->save();

            // Create a lookup to convert the group id to a role id
            $groupReference[$group->id] = $role->id;
        }

        // Convert the group memberships
        $memberships = DB::table('users_groups')->get();
        foreach($memberships as $membership) {
            DB::table('role_users')->insert([
                'user_id' => $membership->user_id,
                'role_id' => $groupReference[$membership->group_id],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Restore the user table data
        $users = EloquentUser::get();
        foreach ($users as $user) {

            // Create an activation record
            if ($activation = Activation::exists($user)) {
                $user->activated_at = $activation->completed_at;
                $user->activated = 1;
            }

            // Convert the user specific permissions
            $user->permissions = $this->restorePermissions($user->permissions);
            $user->save();
        }

        // Convert the roles into
        $roles = DB::table('roles')->get();
        $rolepReference = [];
        foreach($roles as $role) {

            // Create the role
            $group = Sentry::createGroup(array(
                'name' => $role->name,
            ));

            // Convert the permissons
            $permissions = json_decode($role->permissions);
            $group->permissions = $this->restorePermissions($permissions);
            $group->save();

            // Create a lookup to convert the role id to a group id
            $roleReference[$role->id] = $group->id;
        }

        // Convert the group memberships
        $memberships = DB::table('users_groups')->get();
        foreach($memberships as $membership) {
            DB::table('role_users')->insert([
                'user_id' => $membership->user_id,
                'group_id' => $roleReference[$membership->role_id],
            ]);
        }

        Schema::table('throttle', function (Blueprint $table) {
            // Make the user_id column NOT nullable.
            $table->integer('user_id')->unsigned()->change();
            // Drop the added throttle columns
            $table->dropColumn(['type', 'ip', 'created_at', 'updated_at']);
        });

        // Drop the additional Sentinel Tables
        Schema::drop('activations');
        Schema::drop('persistences');
        Schema::drop('reminders');
        Schema::drop('roles');
        Schema::drop('role_users');
    }

    /**
     * Convert Sentry style permission arrays to Sentinel style permission arrays
     *
     * @param  $permissions
     *
     * @return array
     */
    protected function convertPermissions($permissions)
    {
        $converted = [];
        foreach ($permissions as $key => $value) {
            if ($value == -1) {
                $value = 0;
            }
            $converted[$key] = (bool)$value;
        }

        return $converted;
    }

    /**
     * Restore Sentry style permission arrays from Sentinel style permission arrays
     *
     * @param  $permissions
     *
     * @return array
     */
    protected function restorePermissions($permissions)
    {
        $converted = [];
        foreach ($permissions as $key => $value) {
            $converted[$key] = intval($value);
        }

        return $converted;
    }
}
