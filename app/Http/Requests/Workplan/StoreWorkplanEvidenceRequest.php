<?php
namespace App\Http\Requests\Workplan; use Illuminate\Foundation\Http\FormRequest;
class StoreWorkplanEvidenceRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['title'=>['required','string','max:255'],'evidence_type'=>['required','in:file,link'],'file'=>['required_if:evidence_type,file','nullable','file','mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png','max:10240'],'external_url'=>['required_if:evidence_type,link','nullable','url','max:2000'],'notes'=>['nullable','string']];} }
