<?php
namespace App\Http\Requests\Workplan;
class UpdateWorkplanIndicatorRequest extends StoreWorkplanIndicatorRequest { public function rules(): array { return array_map(fn ($rules) => array_merge(['sometimes'], $rules), parent::rules()); } }
