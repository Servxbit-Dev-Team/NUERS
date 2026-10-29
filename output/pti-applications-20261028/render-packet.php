<?php
require __DIR__.'/preview-bootstrap.php';
$service=app(App\Services\PtiApplications::class);
$row=Illuminate\Support\Facades\DB::table('pti_applications')->first();
if(!$row)throw new RuntimeException('Create the synthetic application in the UI first.');
$html=view('reports.pti-application',['record'=>$service->record($row),'documents'=>$service->documents($row->id)->map(fn($d)=>$service->presentDocument($d)),'catalog'=>$service->catalog()])->render();
file_put_contents(__DIR__.'/synthetic-packet.html',$html);
file_put_contents(__DIR__.'/synthetic-packet.pdf',app(App\Services\DocumentPdf::class)->renderHtml($html));
echo "Synthetic application packet rendered\n";
