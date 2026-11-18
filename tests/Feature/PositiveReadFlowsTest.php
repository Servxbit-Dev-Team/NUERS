<?php
namespace Tests\Feature;
use Tests\Support\PositiveFlowCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
class PositiveReadFlowsTest extends PositiveFlowCase
{
    public static function endpoints():array {
        return array_map(fn($v)=>$v,[
            ['admin/analytics?from=2026-01-01&to=2026-12-31',self::REVIEW],
            ['admin/analytics/export?from=2026-01-01&to=2026-12-31',self::REVIEW],
            ['admin/eis-center/batches',self::REVIEW],['admin/infrastructure/actions',['super_admin']],
            ['admin/infrastructure/backups',['super_admin']],['admin/infrastructure/events',['super_admin']],
            ['admin/infrastructure/expansions',['super_admin']],['admin/infrastructure/monitoring',['super_admin']],
            ['admin/infrastructure/servers/{node}',['super_admin']],['admin/integration-source-profiles',self::ADM],
            ['admin/subscription-plans',self::ADM],['admin/wallets',['super_admin']],['admin/wallets/{merchant}/audit',['super_admin']],
            ['business-registry/template',self::REVIEW],['citizen-approvals',self::REVIEW],
            ['compliance-operations/businesses',self::BUSINESS],['compliance-operations/documents?merchant_id={merchant}&source=invoice&search=SYN',self::BUSINESS],
            ['merchant/api-branches',self::BUSINESS],['merchant/expenses',self::BUSINESS],['merchant/pos-devices',self::BUSINESS],
            ['merchant/receipts',self::BUSINESS],['merchant/transactions',self::BUSINESS],
            ['operations/readiness',self::REVIEW],['rdos',['super_admin','admin','bir','rdo']],['transactions/overview',self::REVIEW],
        ]);
    }
    #[DataProvider('endpoints')]
    public function test_valid_read_contracts_for_every_role(string $path,array $allowed):void {
        $this->allRoles(function($role,$u,$h,$m)use($path,$allowed){
            $id=$this->invoice($m);
            $this->artisan('reports:refresh',['--rebuild'=>true,'--activate'=>true])->assertSuccessful();
            $node=DB::table('infra_nodes')->value('id');
            $url='/api/'.str_replace(['{merchant}','{node}'],[$m,$node],$path);
            $r=$this->checked($this->getJson($url,$h),$role,$allowed);
            if(in_array($role,$allowed)){
                if(str_contains($path,'analytics/export'))$this->assertStringContainsString('Seller', $r->streamedContent());
                elseif(str_contains($path,'compliance-operations/documents'))$r->assertJsonPath('data.0.id',$id);
                elseif($path==='compliance-operations/businesses')$r->assertJsonFragment(['id'=>$m]);
                elseif(str_contains($path,'merchant/'))$this->assertStringNotContainsString('api_token',$r->getContent());
                else $this->assertNotEmpty($r->getContent() ?: $r->streamedContent());
            }
        });
    }
}
