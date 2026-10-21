<?php

namespace App\Mcp\Tools;

use App\Concerns\TokenScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An MCP tool that behaves exactly like its /api/v1 counterpart: same token scope, same validation rules
 * (taken from the API's FormRequest), same actions. Only the transport differs.
 */
abstract class ApiTool extends Tool
{
    use TokenScope;

    abstract protected function run(Request $request): Response;

    public function handle(Request $request): Response
    {
        try {
            return $this->run($request);
        } catch (ModelNotFoundException) {
            return Response::error('Not found.');
        } catch (HttpException $e) {
            // The package hides generic exception messages outside debug; these ones are meant for the caller.
            return Response::error($e->getMessage());
        }
    }

    /**
     * Validate the tool arguments with the rules of an API FormRequest, as the token's team.
     *
     * @param  class-string<FormRequest>  $formRequest
     * @return array<string, mixed>
     */
    protected function validateWith(Request $request, string $formRequest): array
    {
        $team = $this->team($request);
        $rules = [(new $formRequest)->setUserResolver(fn () => $team), 'rules'];

        return $request->validate(is_callable($rules) ? $rules() : []);
    }

    /**
     * @param  JsonResource|array<mixed>  $resource
     */
    protected function json(JsonResource|array $resource): Response
    {
        return Response::json($resource instanceof JsonResource ? $resource->resolve() : $resource);
    }
}
