<?php

namespace Difel\CRUDHelper;

use App\User;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BaseControllerTest extends TestCase {
    use DatabaseTransactions, MakesHttpRequests;

    protected $route_prefix;
    protected $route_parameters = [];
    protected $controller;
    protected $controller_instance;
    protected $factory;
    protected $expected_json_structure = ['name'];
    protected $invalid_data = ['invalid_field' => 'Type name'];
    protected $creation_data;
    protected $update_data;
    protected $filter_field = 'name';
    protected $filter_param = 'name-like-%testFilterModelInstance%';
    protected $filter_creation_data = ['name' => 'testFilterModelInstance'];
    protected $filter_value = '%testFilterModelInstance%';
    protected $filter_expected_value = 'testFilterModelInstance';
    protected $order_by_first_element_data = ['name' => 'aa'];
    protected $order_by_last_element_data = ['name' => 'zz'];
    protected $order_by_field = 'name';
    protected $missing_field_in_fields_restriction = 'name';

    protected function setUp() {
        parent::setUp();

        Passport::actingAs(factory(User::class)->create());

        $this->controller_instance = resolve($this->controller);
        $this->route_prefix = $this->controller_instance->getSlugPlural();
        $this->factory = factory($this->controller_instance->getModelClass());

        $this->creation_data = $this->factory->raw();
        $this->update_data = $this->factory->raw();
    }

    public function testList() {
        factory($this->controller_instance->getModelClass(), 10)->create($this->creation_data);

        $response = $this->json('GET', route($this->route_prefix . '.index', $this->route_parameters));

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [$this->controller_instance->getSlugPlural()]
            ]);
    }

    public function testCreate() {
        $response = $this->json('POST', route($this->route_prefix . '.store', $this->route_parameters), $this->creation_data);

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    $this->controller_instance->getSlugSingular() => $this->expected_json_structure
                ]
            ]);

        $newId = $response->json()['data'][$this->controller_instance->getSlugSingular()]['id'];

        $this->assertNotNull($this->controller_instance->getModelClass()::find($newId));
    }

    public function testCreateWithInvalidData() {
        $response = $this->json('POST', route($this->route_prefix . '.store', $this->route_parameters), $this->invalid_data);

        $response
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('exception.validation_error')]);
    }

    public function testUpdate() {
        $model_instance = factory($this->controller_instance->getModelClass())->create($this->creation_data);

        $response = $this->json('PUT', route($this->route_prefix . '.update', array_merge($this->route_parameters, ['id' => $model_instance->id])), $this->update_data);

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    $this->controller_instance->getSlugSingular() => $this->expected_json_structure
                ]
            ])
            ->assertJsonFragment($this->update_data);
    }

    public function testUpdateWithInvalidData() {
        $model_instance = factory($this->controller_instance->getModelClass())->create($this->creation_data);

        $response = $this->json('PUT', route($this->route_prefix . '.update', array_merge($this->route_parameters, ['id' => $model_instance->id])), $this->invalid_data);

        $response
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('exception.validation_error')]);
    }

    public function testUpdateWithInvalidId() {
        $response = $this->json('PUT', route($this->route_prefix . '.update', array_merge($this->route_parameters, ['id' => -1])), $this->update_data);

        $response->assertStatus(404);
    }

    public function testShow() {
        $model_instance = factory($this->controller_instance->getModelClass())->create($this->creation_data);

        $response = $this->json('GET', route($this->route_prefix . '.show', array_merge($this->route_parameters, ['id' => $model_instance->id])));

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    $this->controller_instance->getSlugSingular() => $this->expected_json_structure
                ]
            ])
            ->assertJsonFragment(['id' => $model_instance->id]);
    }

    public function testShowWithInvalidId() {
        $response = $this->json('GET', route($this->route_prefix . '.show', array_merge($this->route_parameters, ['id' => -1])));

        $response->assertStatus(404);
    }

    public function testDelete() {
        $model_instance = factory($this->controller_instance->getModelClass())->create($this->creation_data);

        $response = $this->json('DELETE', route($this->route_prefix . '.destroy', array_merge($this->route_parameters, ['id' => $model_instance->id])));

        $response->assertStatus(200);

        $this->assertNull($this->controller_instance->getModelClass()::find($model_instance->id));
    }

    public function testDeleteWithInvalidId() {
        $response = $this->json('DELETE', route($this->route_prefix . '.destroy', array_merge($this->route_parameters, ['id' => -1])));

        $response->assertStatus(404);
    }

    public function testPaginate() {
        factory($this->controller_instance->getModelClass(), 10)->create($this->creation_data);

        $page_size = 3;

        $response = $this->json('GET', route($this->route_prefix . '.index', array_merge($this->route_parameters, [
            'page_size' => $page_size,
            'page' => 2,
        ])));

        $response->assertStatus(200);

        $responseArray = json_decode($response->getContent());

        $this->assertEquals(count($responseArray->data->{$this->controller_instance->getSlugPlural()}->data), $page_size);
    }

    public function testFilter() {
        factory($this->controller_instance->getModelClass())->create($this->creation_data);
        factory($this->controller_instance->getModelClass())->create($this->filter_creation_data);

        $response = $this->json('GET', route($this->route_prefix . '.index', array_merge($this->route_parameters, [
            'filters' => $this->filter_param,
        ])));

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [$this->controller_instance->getSlugPlural()]
            ])
            ->assertJsonFragment([$this->filter_field => $this->filter_expected_value]);

        $responseArray = json_decode($response->getContent());

        $this->assertGreaterThanOrEqual( 1, count($responseArray->data->{$this->controller_instance->getSlugPlural()}->data));
    }

    public function testOrderBy() {
        factory($this->controller_instance->getModelClass())->create($this->order_by_first_element_data);
        factory($this->controller_instance->getModelClass())->create($this->order_by_last_element_data);

        $response = $this->json('GET', route($this->route_prefix . '.index', array_merge($this->route_parameters, [
            'order_by' => $this->order_by_field . '-asc',
            'page' => 1,
            'page_size' => 1
        ])));

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [$this->controller_instance->getSlugPlural()]
            ])
            ->assertJsonFragment($this->order_by_first_element_data);
    }

    public function testOrderByDesc() {
        factory($this->controller_instance->getModelClass())->create($this->order_by_first_element_data);
        factory($this->controller_instance->getModelClass())->create($this->order_by_last_element_data);

        $response = $this->json('GET', route($this->route_prefix . '.index', array_merge($this->route_parameters, [
            'order_by' => $this->order_by_field . '-desc',
            'page' => 1,
            'page_size' => 2
        ])));

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [$this->controller_instance->getSlugPlural()]
            ])
            ->assertJsonFragment($this->order_by_last_element_data);
    }

    public function testFieldRestriction() {
        $response = $this->json('GET', route($this->route_prefix . '.index', array_merge($this->route_parameters, [
            'fields' => 'id,created_at,updated_at',
        ])));

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [$this->controller_instance->getSlugPlural()]
            ])
            ->assertJsonMissing([
                $this->missing_field_in_fields_restriction
            ]);
    }
}