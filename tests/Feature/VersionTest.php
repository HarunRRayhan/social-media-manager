<?php

it('serves the deployed commit', function () {
    config(['app.commit' => 'abc123']);

    $this->getJson('/version')->assertOk()->assertExactJson(['commit' => 'abc123']);
});
