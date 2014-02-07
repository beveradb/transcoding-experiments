<?php
class HelloWorld {
	public $helloWorld;
	public function __construct($string = 'Hello World!') {
		$this->helloWorld = $string;
	}
	public function sayHello() {
		return $this->helloWorld;
	}
}
