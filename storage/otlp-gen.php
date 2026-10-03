<?php
require 'vendor/autoload.php';
use Core\Support\Integration\Telemetry\OtlpHttpSpanExporter;
use Core\Support\Integration\Transport\HttpTransport;
use Core\Support\Observability\Tracing\{Tracer,SpanKind};
$t = new Tracer(true);
$root = $t->start('POST /v1/payments/payin', SpanKind::Server, ['http.route'=>'payments.payin']);
$child = $t->start('HTTP POST', SpanKind::Client, ['server.address'=>'my-coolpay.com'], parentSpanId: $root->spanId);
usleep(20000); $t->end($child); $t->end($root);
$x = new class implements HttpTransport { public $b; public function send($m,$u,$h=[],$b=null,$to=null): Core\Support\Integration\ExternalResponse { $this->b=$b; return new Core\Support\Integration\ExternalResponse(200);} };
(new OtlpHttpSpanExporter($x, new Psr\Log\NullLogger, 'http://x', 'core-system'))->export($t->flush());
file_put_contents('/app/storage/otlp.json', $x->b); echo $root->traceId;
