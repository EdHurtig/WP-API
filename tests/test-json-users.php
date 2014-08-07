<?php

/**
 * Unit tests covering WP_JSON_Users functionality.
 *
 * @package WordPress
 * @subpackage JSON API
 */
class WP_Test_JSON_User extends WP_UnitTestCase {
	public function setUp() {
		parent::setUp();

		$this->subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->subscriber_obj = get_user_by( 'id', $this->subscriber );
		$this->author = $this->factory->user->create( array( 'role' => 'author' ) );
		$this->author_obj = get_user_by( 'id', $this->author );
		$this->administrator = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->administrator_obj = get_user_by( 'id', $this->administrator );

		$this->fake_server = $this->getMock('WP_JSON_Server');
		$this->endpoint = new WP_JSON_Users( $this->fake_server );
	}

	protected function allow_user_to_create_users( $user ) {
		if ( is_multisite() ) {
			update_site_option( 'site_admins', array( $user->user_login ) );
		} else {
			$user->set_role( 'administrator' );
		}
	}

	protected function check_get_user_response( $response, $user_obj, $context = 'view' ) {
		$response_data = $response->get_data();

		// Check basic data
		$this->assertEquals( $user_obj->ID, $response_data['id'] );
		$this->assertEquals( $user_obj->user_nicename, $response_data['slug'] );
		if ( $context === 'view' ) {

			// Check that we didn't get extra data
			$this->assertArrayNotHasKey( 'extra_capabilities', $response_data );
		} elseif ( $context === 'view-private' ) {
			$this->assertEquals( $user_obj->user_email, $response_data['email'] );
			$this->assertEquals( $user_obj->caps, $response_data['extra_capabilities'] );
		}
	}

	public function test_get_current_user_logged_out() {
		wp_set_current_user( 0 );
		$response = $this->endpoint->get_current_user();
		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'json_not_logged_in', $response->get_error_code() );
	}

	public function test_get_current_user_logged_in() {
		wp_set_current_user( $this->author );
		$response = $this->endpoint->get_current_user();
		$this->assertNotInstanceOf( 'WP_Error', $response );
		$response = json_ensure_response( $response );
		$this->assertEquals( 302, $response->get_status() );
		$headers = $response->get_headers();
		$response_data = $response->get_data();
		$this->assertArrayHasKey( 'Location', $headers );
		$this->check_get_user_response( $response, $this->author_obj, 'view' );
	}

	public function test_get_user_logged_out_view_only() {
		wp_set_current_user( 0 );
		// edit context
		$response = $this->endpoint->get_user( $this->author, 'edit' );
		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'json_user_cannot_edit', $response->get_error_code() );
		// view-private context
		$response = $this->endpoint->get_user( $this->author, 'view-private' );
		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'json_user_cannot_view', $response->get_error_code() );
		// view context
		$response = $this->endpoint->get_user( $this->author, 'view' );
		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'json_user_cannot_view', $response->get_error_code() );
	}

	public function test_get_user_invalid() {
		wp_set_current_user( 0 );
		$response = $this->endpoint->get_user( 9999999, 'view' );
		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'json_invalid_user', $response->get_error_code() );
	}

	public function get_user_logged_in_edit_context() {
		wp_set_current_user( $this->author );
		$response = $this->endpoint->get_user( $this->user, 'edit' );
		$this->assertNotInstanceOf( 'WP_Error', $response );
		$response = json_ensure_response( $response );

		$this->assertEquals( 200, $response->get_status() );
		$this->check_get_user_response( $response, $this->author_obj, 'edit' );
	}

	public function test_create_user_logged_out() {
		wp_set_current_user( 0 );
		$data = array(
			'username' => 'test_user',
			'password' => 'test_password',
			'email' => 'test@example.com',
		);
		$response = $this->endpoint->create_user( $data );
		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'json_cannot_create', $response->get_error_code() );
	}

	public function test_create_user_logged_in() {
		wp_set_current_user( $this->administrator );
		$this->allow_user_to_create_users( $this->administrator_obj );
		$data = array(
			'username' => 'test_user',
			'password' => 'test_password',
			'email'    => 'test@example.com',
			'role'     => 'author',
		);
		$response = $this->endpoint->create_user( $data );
		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 201, $response->get_status() );
		$response_data = $response->get_data();
		$new_user = get_userdata( $response_data['id'] );
		$role = array_shift( $new_user->roles );
		$this->assertEquals( $data['username'], $response_data['username'] );
		$this->assertEquals( $data['username'], $new_user->user_login );
		$this->assertEquals( $data['email'], $new_user->user_email );
		$this->assertEquals( $data['role'], $role );
		$this->assertTrue( wp_check_password( $data['password'], $new_user->user_pass ), 'Password check failed' );
	}

	public function test_create_user_missing_params() {
		wp_set_current_user( $this->administrator );
		$this->allow_user_to_create_users( $this->administrator_obj );
		$data = array(
			'username' => 'test_user',
		);
		$response = $this->endpoint->create_user( $data );
		$this->assertInstanceOf( 'WP_Error', $response );
	}

	public function test_create_user_invalid_role() {
		wp_set_current_user( $this->administrator );
		$this->allow_user_to_create_users( $this->administrator_obj );
		$data = array(
			'username' => 'test_user',
			'password' => 'test_password',
			'email'    => 'test@example.com',
			'role'     => 'invalid_role',
		);
		$response = $this->endpoint->create_user( $data );
		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'json_invalid_role', $response->get_error_code() );
	}

	public function test_edit_user_change_role() {
		wp_set_current_user( $this->administrator );
		$this->allow_user_to_create_users( $this->administrator_obj );
		$data = array(
			'role'     => 'editor',
		);
		$response = $this->endpoint->edit_user( $this->author, $data );
		$this->assertNotInstanceOf( 'WP_Error', $response );
		$response = json_ensure_response( $response );
		$this->assertEquals( 200, $response->get_status() );
		$response_data = $response->get_data();
		$existing_author = get_userdata( $this->author );
		$role = array_shift( $existing_author->roles );
		$this->assertEquals( 'editor', $role );
	}

	public function test_delete_user() {
		wp_set_current_user( $this->administrator );
		$this->allow_user_to_create_users( $this->administrator_obj );

		// Test with a new user, rather than ourselves, to avoid any
		// complications with doing so. We should check this separately though.
		$test_user = $this->factory->user->create();
		$response = $this->endpoint->delete_user( $test_user );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$response = json_ensure_response( $response );

		// Check that we succeeded
		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_delete_user_reassign() {
		wp_set_current_user( $this->administrator );
		$this->allow_user_to_create_users( $this->administrator_obj );

		// Test with a new user, rather than ourselves, to avoid any
		// complications with doing so. We should check this separately though.
		$test_user = $this->factory->user->create();
		$test_new_author = $this->factory->user->create();
		$test_post = $this->factory->post->create(array(
			'post_author' => $test_user,
		));

		// Sanity check to ensure the factory created the post correctly
		$post = get_post( $test_post );
		$this->assertEquals( $test_user, $post->post_author );

		// Delete our test user, and reassign to the new author
		$response = $this->endpoint->delete_user( $test_user, false, $test_new_author );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$response = json_ensure_response( $response );

		// Check that we succeeded
		$this->assertEquals( 200, $response->get_status() );

		// Check that the post has been updated correctly
		$post = get_post( $test_post );
		$this->assertEquals( $test_new_author, $post->post_author );
	}

	public function test_update_user() {
		wp_set_current_user( $this->administrator );
		$pw_before = $this->administrator_obj->user_pass;

		$data = array(
			'first_name' => 'New Name',
		);
		$response = $this->endpoint->edit_user( $this->administrator, $data );
		$this->assertNotInstanceOf( 'WP_Error', $response );
		$response = json_ensure_response( $response );

		// Check that we succeeded
		$this->assertEquals( 200, $response->get_status() );

		// Check that the name has been updated correctly
		$new_data = $response->get_data();
		$this->assertEquals( $data['first_name'], $new_data['first_name'] );

		$user = get_userdata( $this->administrator );
		$this->assertEquals( $user->first_name, $data['first_name'] );

		// Check that we haven't inadvertently changed the user's password,
		// as per https://core.trac.wordpress.org/ticket/21429
		$this->assertEquals( $pw_before, $user->user_pass );
	}

	public function test_get_users_logged_out() {
		wp_set_current_user( 0 );
		$response = $this->endpoint->get_users();
		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'json_user_cannot_list', $response->get_error_code() );
	}

	public function test_get_users_logged_in_subscriber() {
		wp_set_current_user( $this->subscriber );
		$response = $this->endpoint->get_users();
		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'json_user_cannot_list', $response->get_error_code() );
	}

	public function test_get_users_logged_in_administrator() {
		wp_set_current_user( $this->administrator );
		$response = $this->endpoint->get_users();
		$this->assertNotInstanceOf( 'WP_Error', $response );

	}

}
