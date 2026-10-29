<?php
namespace Tests\Feature;
require __DIR__.'/../../tests/Feature/DocumentCopyMailTest.php';
class DocumentCopyDiagnosticTest extends DocumentCopyMailTest {
protected function setUp():void{parent::setUp();$this->app->instance(\App\Services\DocumentPdf::class,new class extends \App\Services\DocumentPdf{public function render(array $document):string{try{return parent::render($document);}catch(\Throwable$e){fwrite(STDERR,"PDF failure: ".$e->getMessage()."\n");throw$e;}}});}
}
