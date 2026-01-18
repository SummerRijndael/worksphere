<?php

namespace Tests\Unit;

use App\Services\FileSecurityValidator;
use App\Services\MediaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class MediaServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_attach_validates_file_before_processing()
    {
        Storage::fake('public');

        // Mock FileSecurityValidator
        $validatorMock = Mockery::mock(FileSecurityValidator::class);
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        // Expect validation to be called
        $validatorMock->shouldReceive('validate')
            ->once()
            ->with($file);

        // Mock Model for media attachment
        $modelMock = Mockery::mock(Model::class);
        $mediaMock = Mockery::mock('Spatie\MediaLibrary\MediaCollections\Models\Media'); // Pseudo-mock for return type

        $fileAdderMock = Mockery::mock('Spatie\MediaLibrary\MediaCollections\FileAdder');

        $modelMock->shouldReceive('addMedia')
            ->once()
            ->with($file->getRealPath())
            ->andReturn($fileAdderMock);

        $fileAdderMock->shouldReceive('usingFileName')
            ->once()
            ->andReturnSelf();

        $fileAdderMock->shouldReceive('usingName')
            ->once()
            ->andReturnSelf();

        $fileAdderMock->shouldReceive('toMediaCollection')
            ->once()
            ->with('documents')
            ->andReturn($mediaMock);

        $service = new MediaService($validatorMock);
        $result = $service->attach($modelMock, $file, 'documents');

        $this->assertSame($mediaMock, $result);
    }

    public function test_attach_uses_custom_filename()
    {
        Storage::fake('public');

        $validatorMock = Mockery::mock(FileSecurityValidator::class);
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $validatorMock->shouldReceive('validate')->once()->with($file);

        $modelMock = Mockery::mock(Model::class);
        $mediaMock = Mockery::mock('Spatie\MediaLibrary\MediaCollections\Models\Media'); // Pseudo-mock

        $fileAdderMock = Mockery::mock('Spatie\MediaLibrary\MediaCollections\FileAdder');

        $modelMock->shouldReceive('addMedia')
            ->once()
            ->with($file->getRealPath())
            ->andReturn($fileAdderMock);

        $fileAdderMock->shouldReceive('usingFileName')
            ->once()
            ->with('custom.pdf')
            ->andReturnSelf();

        $fileAdderMock->shouldReceive('usingName')
            ->once()
            ->with('Custom Name')
            ->andReturnSelf();

        $fileAdderMock->shouldReceive('toMediaCollection')
            ->once()
            ->with('documents')
            ->andReturn($mediaMock);

        $service = new MediaService($validatorMock);
        $result = $service->attach($modelMock, $file, 'documents', 'custom.pdf', 'Custom Name');

        $this->assertSame($mediaMock, $result);
    }

    public function test_attach_from_request_validates_and_attaches()
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        // Mock request
        request()->files->set('file', $file);

        $validatorMock = Mockery::mock(FileSecurityValidator::class);
        $validatorMock->shouldReceive('validate')->once()->with($file);

        $modelMock = Mockery::mock(Model::class);
        $mediaMock = Mockery::mock('Spatie\MediaLibrary\MediaCollections\Models\Media');
        $fileAdderMock = Mockery::mock('Spatie\MediaLibrary\MediaCollections\FileAdder');

        $modelMock->shouldReceive('addMedia')->andReturn($fileAdderMock);
        $fileAdderMock->shouldReceive('usingFileName')->andReturnSelf();
        $fileAdderMock->shouldReceive('usingName')->andReturnSelf();
        $fileAdderMock->shouldReceive('toMediaCollection')->andReturn($mediaMock);

        $service = new MediaService($validatorMock);
        $result = $service->attachFromRequest($modelMock, 'file', 'documents');

        $this->assertSame($mediaMock, $result);
    }

    public function test_attach_throws_exception_on_invalid_file()
    {
        // Mock FileSecurityValidator to throw exception
        $validatorMock = Mockery::mock(FileSecurityValidator::class);
        $file = UploadedFile::fake()->create('malicious.php', 100);

        $validatorMock->shouldReceive('validate')
            ->once()
            ->with($file)
            ->andThrow(ValidationException::withMessages(['file' => 'Invalid file']));

        $modelMock = Mockery::mock(Model::class);

        // Expect addMedia NOT to be called
        $modelMock->shouldReceive('addMedia')->never();

        $service = new MediaService($validatorMock);

        $this->expectException(ValidationException::class);

        $service->attach($modelMock, $file, 'uploads');
    }
}
