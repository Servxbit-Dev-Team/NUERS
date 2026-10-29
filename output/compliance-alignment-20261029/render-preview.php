<?php
require __DIR__.'/preview-bootstrap.php';
$rows=Illuminate\Support\Facades\DB::table('merchant_compliance_records as c')->join('merchants as m','m.id','=','c.merchant_id')->select('c.*','m.business_name','m.tin')->get()->map(fn($x)=>app(App\Services\ComplianceOperations::class)->row($x));
$html=view('reports.compliance-operations',['rows'=>$rows,'logo'=>'data:image/png;base64,'.base64_encode(file_get_contents(public_path('auth-assets/nuers-digital-logo.png'))),'filters'=>['scope'=>'SYNTHETIC LAB DATA ONLY'],'generated'=>now('Asia/Manila')->format('Y-m-d H:i:s P'),'version'=>'1.0.0'])->render();
file_put_contents(__DIR__.'/Compliance-Register-Lab.pdf',app(App\Services\DocumentPdf::class)->renderHtml($html));
echo "Rendered synthetic compliance report\n";
