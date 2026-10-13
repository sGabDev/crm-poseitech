Add-Type -AssemblyName System.Drawing
$assetRoot = Join-Path $PSScriptRoot '../public'
$green = [Drawing.ColorTranslator]::FromHtml('#176b55')
$white = [Drawing.SolidBrush]::new([Drawing.Color]::White)
$background = [Drawing.SolidBrush]::new($green)
function New-BrandIcon([int]$size, [string]$name) {
    $bitmap = [Drawing.Bitmap]::new($size,$size)
    $graphics = [Drawing.Graphics]::FromImage($bitmap)
    $graphics.Clear($green)
    $graphics.TextRenderingHint = [Drawing.Text.TextRenderingHint]::AntiAliasGridFit
    $font = [Drawing.Font]::new('Segoe UI',($size * 0.66),[Drawing.FontStyle]::Bold,[Drawing.GraphicsUnit]::Pixel)
    $format = [Drawing.StringFormat]::new()
    $format.Alignment = [Drawing.StringAlignment]::Center
    $format.LineAlignment = [Drawing.StringAlignment]::Center
    $graphics.DrawString('P',$font,$white,[Drawing.RectangleF]::new(0,-($size*0.035),$size,$size),$format)
    $bitmap.Save((Join-Path $assetRoot $name),[Drawing.Imaging.ImageFormat]::Png)
    $format.Dispose(); $font.Dispose(); $graphics.Dispose(); $bitmap.Dispose()
}
New-BrandIcon 32 'favicon-32.png'
New-BrandIcon 180 'apple-touch-icon.png'
New-BrandIcon 192 'icon-192.png'
New-BrandIcon 512 'icon-512.png'
$png = [IO.File]::ReadAllBytes((Join-Path $assetRoot 'favicon-32.png'))
$stream = [IO.File]::Create((Join-Path $assetRoot 'favicon.ico'))
$writer = [IO.BinaryWriter]::new($stream)
$writer.Write([uint16]0); $writer.Write([uint16]1); $writer.Write([uint16]1)
$writer.Write([byte]32); $writer.Write([byte]32); $writer.Write([byte]0); $writer.Write([byte]0)
$writer.Write([uint16]1); $writer.Write([uint16]32); $writer.Write([uint32]$png.Length); $writer.Write([uint32]22); $writer.Write($png)
$writer.Dispose(); $stream.Dispose()
$bitmap = [Drawing.Bitmap]::new(1200,630)
$graphics = [Drawing.Graphics]::FromImage($bitmap)
$graphics.Clear([Drawing.ColorTranslator]::FromHtml('#102d24'))
$graphics.SmoothingMode = [Drawing.Drawing2D.SmoothingMode]::AntiAlias
$graphics.TextRenderingHint = [Drawing.Text.TextRenderingHint]::AntiAliasGridFit
$graphics.FillEllipse($background,920,-180,600,900)
$graphics.FillRectangle($background,74,130,112,112)
$font = [Drawing.Font]::new('Segoe UI',80,[Drawing.FontStyle]::Bold,[Drawing.GraphicsUnit]::Pixel)
$graphics.DrawString('P',$font,$white,95,129)
$graphics.DrawString('PoseiTech',$font,$white,65,270)
$subtitle = [Drawing.Font]::new('Segoe UI',33,[Drawing.FontStyle]::Regular,[Drawing.GraphicsUnit]::Pixel)
$graphics.DrawString('Vendas. Clientes. Atendimento.',$subtitle,$white,72,385)
$caption = [Drawing.Font]::new('Segoe UI',22,[Drawing.FontStyle]::Regular,[Drawing.GraphicsUnit]::Pixel)
$graphics.DrawString('CRM & GESTAO',$caption,$white,72,520)
$bitmap.Save((Join-Path $assetRoot 'assets/share-card.png'),[Drawing.Imaging.ImageFormat]::Png)
$font.Dispose(); $subtitle.Dispose(); $caption.Dispose(); $graphics.Dispose(); $bitmap.Dispose(); $white.Dispose(); $background.Dispose()
Write-Output 'Brand icons and sharing image generated.'
