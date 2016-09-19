<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RemoveSentrySchema extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Remove the user table columns that are no longer needed
        Schema::table('users', function(Blueprint $table){
            $table->dropColumn([
                'activated', 'activation_code', 'activated_at', 'persist_code',
                'reset_password_code'
            ]);
        });

        // Remove the throtle table columsn that are no longer needed
        Schema::table('throttle', function(Blueprint $table){
            $table->dropColumn([
                'ip_address', 'attempts', 'suspended', 'banned',
                'last_attempt_at', 'suspended_at', 'banned_at'
            ]);
        });

        // Drop the groups table
        Schema::drop('groups');
        Schema::drop('users_groups');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Restore the missing user table columns
        Schema::table('users', function ($table) {
            $table->boolean('activated')->default(0);
            $table->string('activation_code')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('persist_code')->nullable();
            $table->string('reset_password_code')->nullable();

            // We'll need to ensure that MySQL uses the InnoDB engine to
            // support the indexes, other engines aren't affected.
            $table->engine = 'InnoDB';
            $table->index('activation_code');
            $table->index('reset_password_code');
        });

        // Restore the missing throttle columns
        Schema::create('throttle', function ($table) {
            $table->string('ip_address')->nullable();
            $table->integer('attempts')->default(0);
            $table->boolean('suspended')->default(0);
            $table->boolean('banned')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('banned_at')->nullable();
        });

        // Create the groups table
        Schema::create('groups', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->text('permissions')->nullable();
            $table->timestamps();

            // We'll need to ensure that MySQL uses the InnoDB engine to
            // support the indexes, other engines aren't affected.
            $table->engine = 'InnoDB';
            $table->unique('name');
        });

        // Create the users_groups table
        Schema::create('users_groups', function ($table) {
            $table->integer('user_id')->unsigned();
            $table->integer('group_id')->unsigned();

            // We'll need to ensure that MySQL uses the InnoDB engine to
            // support the indexes, other engines aren't affected.
            $table->engine = 'InnoDB';
            $table->primary(array('user_id', 'group_id'));
        });
    }
}
