<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Shared\Ports\ObjectStorage;
use App\Domain\Video\ValueObjects\FailureReason;
use App\Domain\Video\ValueObjects\StorageKey;
use App\Domain\Video\ValueObjects\VideoId;
use App\Infrastructure\Config\ParameterResolver;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentVideoRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InMemoryObjectStorage;
use Tests\Support\JwtFactory;
use Tests\TestCase;

/** Rotas /api/videos e /api/health contra o MySQL real (cada teste numa transação desfeita no fim). */
final class VideoApiTest extends TestCase
{
    use DatabaseTransactions;

    private const ANA = '0192f1c4-6a00-7b00-8c00-1d2e3f4a5b6c';

    private const BETO = '0192f1c4-6a00-7b00-8c00-1d2e3f4a5b6d';

    private InMemoryObjectStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        JwtFactory::install($this->app);
        $this->storage = new InMemoryObjectStorage;
        $this->app->instance(ObjectStorage::class, $this->storage);
    }

    /** @return array<string, string> */
    private function auth(string $user = self::ANA, array $extra = []): array
    {
        return ['Authorization' => 'Bearer '.JwtFactory::token($user, $user === self::ANA ? 'ana@exemplo.com' : 'beto@exemplo.com')] + $extra;
    }

    private function criar(string $user = self::ANA, string $nome = 'aula-01.mp4'): string
    {
        return $this->postJson('/api/videos', ['filename' => $nome, 'size_bytes' => 73400320, 'content_type' => 'video/mp4'], $this->auth($user))
            ->assertCreated()->json('id');
    }

    public function test_post_cria_pendente_e_devolve_post_pre_assinado(): void
    {
        $r = $this->postJson('/api/videos', ['filename' => 'Aula-01.MKV', 'size_bytes' => 73400320, 'content_type' => ''], $this->auth());
        $r->assertCreated()
            ->assertJsonPath('status', 'PENDING_UPLOAD')
            ->assertJsonStructure(['id', 'status', 'upload' => ['url', 'fields', 'expires_at']])
            ->assertJsonPath('upload.fields.key', 'uploads/'.$r->json('id').'.mkv')
            ->assertJsonPath('upload.fields.Content-Type', 'video/x-matroska')
            ->assertHeader('x-correlation-id');
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $r->json('upload.expires_at'));
        $this->assertDatabaseHas('videos', ['id' => $r->json('id'), 'user_id' => self::ANA, 'user_email' => 'ana@exemplo.com', 'status' => 'PENDING_UPLOAD', 'size_bytes' => 73400320]);
    }

    public function test_post_valida_os_campos(): void
    {
        $this->postJson('/api/videos', ['filename' => 'foto.png', 'size_bytes' => 524288001], $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Os dados informados são inválidos.')
            ->assertJsonPath('errors.filename.0', 'O formato do arquivo não é aceito. Use mp4, avi, mov, mkv, wmv, flv ou webm.')
            ->assertJsonPath('errors.size_bytes.0', 'O arquivo deve ter no máximo 500 MB.')
            ->assertJsonPath('errors.content_type.0', 'Informe o tipo do arquivo.');
    }

    public function test_sem_token_ou_com_token_invalido_e_401(): void
    {
        $this->getJson('/api/videos')->assertStatus(401)->assertExactJson(['message' => 'Unauthorized']);
        $this->getJson('/api/videos', ['Authorization' => 'Bearer x.y.z'])->assertStatus(401);
        $this->getJson('/api/videos', ['Authorization' => 'Bearer '.JwtFactory::token(self::ANA, override: ['exp' => time() - 120])])->assertStatus(401);
        $this->getJson('/api/videos', ['Authorization' => 'Basic abc'])->assertStatus(401);
    }

    public function test_x_user_id_diferente_do_token_e_401(): void
    {
        $this->getJson('/api/videos', $this->auth(self::ANA, ['x-user-id' => self::BETO]))->assertStatus(401);
        $this->getJson('/api/videos', $this->auth(self::ANA, ['x-user-id' => self::ANA]))->assertOk();
    }

    public function test_lista_so_os_videos_do_usuario_com_paginacao_e_filtro(): void
    {
        $a1 = $this->criar(self::ANA, 'a1.mp4');
        $this->travel(1)->seconds();
        $a2 = $this->criar(self::ANA, 'a2.mp4');
        $this->criar(self::BETO, 'b1.mp4');

        $this->getJson('/api/videos?per_page=1', $this->auth())->assertOk()
            ->assertJsonPath('meta', ['page' => 1, 'per_page' => 1, 'total' => 2])
            ->assertJsonPath('data.0.id', $a2)
            ->assertJsonStructure(['data' => [['id', 'original_name', 'size_bytes', 'status', 'frame_count', 'failure_code', 'failure_reason', 'created_at', 'updated_at']]]);
        $this->getJson('/api/videos?page=2&per_page=1', $this->auth())->assertJsonPath('data.0.id', $a1);
        $this->getJson('/api/videos?status=COMPLETED', $this->auth())->assertJsonPath('meta.total', 0);
        $this->getJson('/api/videos?status=QUALQUER', $this->auth())->assertStatus(422);
    }

    public function test_show_e_isolamento_entre_usuarios(): void
    {
        $id = $this->criar();
        $this->getJson("/api/videos/{$id}", $this->auth())->assertOk()->assertJsonPath('original_name', 'aula-01.mp4')->assertJsonPath('failure_code', null);
        $this->getJson("/api/videos/{$id}", $this->auth(self::BETO))->assertNotFound()->assertJsonPath('message', 'Vídeo não encontrado.');
        $this->getJson('/api/videos/nao-e-uuid', $this->auth())->assertNotFound();
    }

    public function test_download_409_ate_concluir_e_200_depois(): void
    {
        $id = $this->criar();
        $this->getJson("/api/videos/{$id}/download", $this->auth())->assertStatus(409)->assertExactJson(['message' => 'O vídeo ainda não está pronto para download.']);

        $repo = new EloquentVideoRepository;
        $video = $repo->find(new VideoId($id));
        $now = new \DateTimeImmutable;
        $video->markUploaded($now);
        $video->markQueued($now);
        $video->complete(StorageKey::framesZip($video->id), 42, $now);
        $repo->save($video);

        $this->getJson("/api/videos/{$id}/download", $this->auth())->assertOk()
            ->assertJsonPath('url', "http://s3.teste/bucket/results/{$id}/frames.zip?X-Amz-Expires=900");
        $this->getJson("/api/videos/{$id}", $this->auth())->assertJsonPath('frame_count', 42)->assertJsonPath('status', 'COMPLETED');
        $this->getJson("/api/videos/{$id}/download", $this->auth(self::BETO))->assertNotFound();
    }

    public function test_falha_mostra_codigo_e_texto(): void
    {
        $id = $this->criar();
        $repo = new EloquentVideoRepository;
        $video = $repo->find(new VideoId($id));
        $video->markUploaded(new \DateTimeImmutable);
        $video->fail(FailureReason::InvalidUpload, new \DateTimeImmutable);
        $repo->save($video);
        $this->getJson("/api/videos/{$id}", $this->auth())
            ->assertJsonPath('status', 'FAILED')
            ->assertJsonPath('failure_code', 'INVALID_UPLOAD')
            ->assertJsonPath('failure_reason', 'O arquivo enviado não é um vídeo válido ou ultrapassa 500 MB');
    }

    public function test_health(): void
    {
        $this->getJson('/api/health/live')->assertOk()->assertExactJson(['status' => 'ok']);
        $this->getJson('/api/health/ready')->assertOk()->assertJsonPath('checks', ['database' => 'ok', 'sqs' => 'ok']);
    }

    public function test_ready_falha_quando_o_sqs_esta_fora(): void
    {
        config(['fiapx.parameters.video_uploaded_queue_url' => ['http://localstack:4566/000000000000/nao-existe', 'x']]);
        $this->app->forgetInstance(ParameterResolver::class);
        $this->getJson('/api/health/ready')->assertStatus(503)->assertJsonPath('status', 'fail')->assertJsonPath('checks.sqs', 'fail');
    }

    public function test_rota_inexistente_e_404_em_json(): void
    {
        $this->getJson('/api/nada')->assertNotFound()->assertJsonPath('message', 'Recurso não encontrado.');
    }

    public function test_correlation_id_recebido_e_devolvido(): void
    {
        $id = '0192f1c4-0000-7000-8000-00000000c0de';
        $this->getJson('/api/health/live', ['x-correlation-id' => $id])->assertHeader('x-correlation-id', $id);
    }
}
