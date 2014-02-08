<?php
/**
 * Deliberately no pre-defined $unset vars since the expected
 * return value if $index is unset depends on the use-case
 */
class Globals {
    public static function GET($index, $unset) {
    	return isset( $_GET[$index] ) ? $_GET[$index] : $unset;
    }
    public static function POST($index, $unset) {
    	return isset( $_POST[$index] ) ? $_POST[$index] : $unset;
    }
    public static function SESSION($index, $unset) {
    	return isset( $_SESSION[$index] ) ? $_SESSION[$index] : $unset;
    }
    public static function SERVER($index, $unset) {
    	return isset( $_SERVER[$index] ) ? $_SERVER[$index] : $unset;
    }
    public static function COOKIE($index, $unset) {
    	return isset( $_COOKIE[$index] ) ? $_COOKIE[$index] : $unset;
    }
    public static function FILES($index, $unset) {
    	return isset( $_FILES[$index] ) ? $_FILES[$index] : $unset;
    }
    public static function REQUEST($index, $unset) {
    	return isset( $_REQUEST[$index] ) ? $_REQUEST[$index] : $unset;
    }
    public static function ENV($index, $unset) {
    	return isset( $_ENV[$index] ) ? $_ENV[$index] : $unset;
    }
}
?>
