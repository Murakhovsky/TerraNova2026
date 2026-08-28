<?php
namespace Interfaces\Telegram\Rendering;
interface CardInterface
{
    public function __construct();
    public function setTitle(string $title): self;
    public function setText(string $text): self;
    public function setPhoto(string $fileIdOrUrl): self;
    public function addButton(string $label, string $callbackData): self;
    public function render(): array;  // повертає масив ready-to-send бота
}

