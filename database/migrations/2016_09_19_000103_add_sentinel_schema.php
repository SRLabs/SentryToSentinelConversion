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
            $table->string('type')->nullable();
            $table->string('ip')->nullable();
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
        Schema::drop('activations');
        Schema::drop('persistences');
        Schema::drop('reminders');
        Schema::drop('roles');
        Schema::drop('role_users');

        Schema::table('throttle', function (Blueprint $table) {
            $table->dropColumn(['type', 'ip']);
        });
    }

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
}
