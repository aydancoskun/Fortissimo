<?php
/** Test the FortissimoContextDump class. */
require_once 'PHPUnit/Framework.php';
require_once 'Fortissimo/skel/src/Fortissimo.php';

class FortissimoEchoTest extends PHPUnit_Framework_TestCase {
  public $xml ='<?xml version="1.0"?>
<commands xmlns="http://technosophos.com/2009/1.1/commands.xml">
<request name="testDoCommand">
  <cmd name="echo" invoke="FortissimoEcho">
    <param name="text">Echo</param>
  </cmd>
</request>
</commands>
';
    
  public function testDoCommand() {
    $ff = new FortissimoHarness($this->xml);
    ob_start();
    $ff->handleRequest('testDoCommand');
    $c = ob_get_contents();
    ob_end_clean();
    $c = trim($c);
    
    $this->assertEquals('Echo', $c);
  }
}