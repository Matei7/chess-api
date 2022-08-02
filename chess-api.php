<?php
/*
Plugin Name: Chess API
Plugin URI: https://github.com/Matei7/chess-api
Version: 0.1
Author: <a href="https://github.com/Matei7">Matei</a>
Description: Chess API
*/
require_once 'inc/class-rest.php';
add_action( 'rest_api_init', 'chess_api_rest_init' );

function chess_api_rest_init() {
	$rest = new \ChessApi\Rest();
	$rest->register_routes();
}
