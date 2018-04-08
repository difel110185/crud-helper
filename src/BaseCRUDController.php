<?php

namespace App\Http\Controllers;

use App\Http\ServerErrorResponse;
use App\Http\SuccessResponse;
use App\Http\ValidationErrorResponse;
use DateTime;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use Illuminate\Validation\ValidationException;

class BaseCRUDController extends Controller
{
  protected $model_name;
  protected $slug_singular;
  protected $slug_plural;
  protected $model_class;
  protected $validation_rules;
  protected $update_validation_rules;
  protected $creation_validation_rules;
  protected $view_class_to_read_from;
  protected $page_size = 50;
  protected $order_by = [];
  protected $fields = null;
  protected $filters = [];
  protected $create_message_key = 'response.created_successfully';
  protected $update_message_key = 'response.updated_successfully';
  protected $delete_message_key = 'response.deleted_successfully';

  protected function index()
  {
    try {
      if ($this->shouldReturnRawData())
        return new SuccessResponse('', [$this->slug_plural => $this->model_class::all()]);

      $this->populateInstanceQueryAttributes();

      $items = $this->getData($this->view_class_to_read_from ?? $this->model_class);

      return new SuccessResponse('', [$this->slug_plural => $items]);
    }
    catch (\Exception $e){
      return new ServerErrorResponse($e);
    }
  }

  protected function store(Request $request)
  {
    try {
      $this->validate(request(), $this->creation_validation_rules ?? $this->validation_rules);

      $model_instance = $this->model_class::create($request->all());

      $data = [$this->slug_singular => $model_instance];

      return new SuccessResponse(__($this->create_message_key, ['model' => $this->model_name]), $data);
    }
    catch (ValidationException $e){
      return new ValidationErrorResponse($e, __('exception.validation_error'));
    }
    catch (\Exception $e){
      return new ServerErrorResponse($e);
    }
  }

  protected function show(int $id)
  {
    try {
      $model_instance = $this->findModelInstance($id);

      return new SuccessResponse('', [$this->slug_singular => $model_instance]);
    }
    catch (ModelNotFoundException $e){
      return new ServerErrorResponse($e, null, null, 404);
    }
    catch (\Exception $e){
      return new ServerErrorResponse($e);
    }
  }

  protected function update(Request $request, int $id)
  {
    try {
      $model_instance = $this->findModelInstance($id);

      $this->validate(request(), $this->update_validation_rules ?? $this->validation_rules);

      $model_instance->fill($request->all());

      $model_instance->save();

      $data = [ $this->slug_singular => $model_instance];

      return new SuccessResponse(__($this->update_message_key, ['model' => $this->model_name]), $data);
    }
    catch (ModelNotFoundException $e){
      return new ServerErrorResponse($e, null, null, 404);
    }
    catch (ValidationException $e){
      return new ValidationErrorResponse($e, __('exception.validation_error'));
    }
    catch (\Exception $e){
      return new ServerErrorResponse($e);
    }
  }

  protected function destroy(int $id)
  {
    try {
      $model_instance = $this->findModelInstance($id);

      $model_instance->delete();

      return new SuccessResponse(__($this->delete_message_key, ['model' => $this->model_name]));
    }
    catch (ModelNotFoundException $e){
      return new ServerErrorResponse($e, null, null, 404);
    }
    catch (\Exception $e){
      return new ServerErrorResponse($e);
    }
  }

  protected function process_filter_value($string) {
    if (preg_match("/^int\((\d+)\)$/", $string, $result))
      return (int) $result[1];

    if (preg_match("/^date\((\d{8})\)$/", $string, $result))
      if (DateTime::createFromFormat('Ymd', $result[1]))
        return DateTime::createFromFormat('Ymd G:i:s', $result[1] . ' 00:00:00');

    if (preg_match("/^datetime\((\d{8} \d{2}\:\d{2}\:\d{2})\)$/", $string, $result))
      if (DateTime::createFromFormat('Ymd G:i:s', $result[1]))
        return DateTime::createFromFormat('Ymd G:i:s', $result[1]);

    return $string;
  }

  protected function shouldReturnRawData(): bool {
    $parameters = Input::all();

    return empty($parameters) ||
                (
                  !isset($parameters['filters']) &&
                  !isset($parameters['order_by']) &&
                  !isset($parameters['fields']) &&
                  !isset($parameters['page_size'])
                );
  }

  protected function populateInstanceQueryAttributes() {
    $parameters = Input::all();

    $this->getFilterParametersFromRequest($parameters);

    $this->getOrderByParametersFromRequest($parameters);

    $this->getFieldsParametersFromRequest($parameters);

    $this->getPaginationParametersFromRequest($parameters);
  }

  protected function getData($model_class) {
    $query = (new $model_class)->newQuery();

    foreach ($this->filters as $f)
      $query->where($f[0], $f[1], $this->process_filter_value($f[2]));

    foreach ($this->order_by as $ob)
      $query->orderBy($ob[0], $ob[1]);

    return (is_null($this->fields)) ? $query->paginate($this->page_size) : $query->paginate($this->page_size, $this->fields);
  }

  protected function getFilterParametersFromRequest($parameters) {
    if (isset($parameters['filters'])) {
      $filters_param = $parameters['filters'];
      $filters = explode(',', $filters_param);

      $this->filters = array_map(function ($array_item) {
        return explode('-', $array_item);
      }, $filters);
    }
    return $parameters;
  }

  protected function getOrderByParametersFromRequest($parameters) {
    if (isset($parameters['order_by'])) {
      $order_by_param = $parameters['order_by'];
      $order_by = explode(',', $order_by_param);

      $this->order_by = array_map(function ($array_item) {
        return explode('-', $array_item);
      }, $order_by);
    }
    return $parameters;
  }

  protected function getFieldsParametersFromRequest($parameters) {
    if (isset($parameters['fields'])) {
      $fields_param = $parameters['fields'];
      $this->fields = explode(',', $fields_param);
    }
    return $parameters;
  }

  protected function getPaginationParametersFromRequest($parameters) {
    if (isset($parameters['page_size']))
      $this->page_size = $parameters['page_size'];
  }

  protected function findModelInstance($id) {
    $this->populateInstanceQueryAttributes();

    $this->filters[] = ['id', '=', 'int(' . $id . ')'];

    $results = $this->getData($this->model_class)->items();

    if(empty($results))
      throw new ModelNotFoundException(__('exception.model_not_found', ['model' => $this->model_name]));

    return $results[0];
  }
}
