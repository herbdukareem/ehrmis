<?php
namespace App\Http\Requests\Workplan;
class UpdateWorkplanObjectiveRequest extends StoreWorkplanObjectiveRequest { public function rules(): array { return array_map(fn ($rules) => array_merge(['sometimes'], $rules), parent::rules()); } }
