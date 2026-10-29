<?php
namespace App\Services;

use Illuminate\Http\Request;

/** Shared spreadsheet escaping and optional bounded PDF source, after caller authorization. */
class ReportCsv
{
    public static function limit(Request $request, int $count): void
    {
        if (!$request->has('report_limit')) return;
        $request->validate(['report_limit'=>'required|integer|min:1|max:500']);
        abort_if($count > $request->integer('report_limit'), 422, 'PDF supports up to 500 matching records. Narrow the filters or choose CSV for the complete export. No partial PDF was created.');
    }
    public static function cell(mixed $value): string
    {
        $text=is_scalar($value) || $value===null ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        return preg_match('/^[\s\x00-\x1f]*[=+@\-]/u',$text) ? "'".$text : $text;
    }
    public static function download(array $headers, iterable $rows, string $filename)
    {
        return response()->streamDownload(function()use($headers,$rows){
            $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$headers,escape:'');
            foreach($rows as $row)fputcsv($out,array_map(self::cell(...),array_values($row)),escape:'');
            fclose($out);
        },$filename,['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public static function flatten(mixed $value, string $path=''): \Generator
    {
        if(is_object($value))$value=(array)$value;
        if(is_array($value)&&count($value))foreach($value as $key=>$item)yield from self::flatten($item,$path===''?(string)$key:$path.'.'.$key);
        else yield [$path,is_array($value)?json_encode($value):$value];
    }
}
