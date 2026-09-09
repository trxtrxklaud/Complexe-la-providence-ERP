<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ParentVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $pseudo
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'رمز تفعيل حساب ولي الأمر - مجمع لا بروفيدانس',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    private function buildHtml(): string
    {
        $code = htmlspecialchars($this->code, ENT_QUOTES, 'UTF-8');
        $pseudo = htmlspecialchars($this->pseudo, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>رمز تفعيل حساب ولي الأمر</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8fafc; color: #1e293b; margin: 0; padding: 24px; direction: rtl; text-align: right; }
        .card { max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .header { background: linear-gradient(135deg, #ea580c 0%, #c2410c 100%); padding: 28px 24px; text-align: center; color: #ffffff; }
        .header h1 { margin: 0; font-size: 22px; font-weight: bold; }
        .header p { margin: 6px 0 0; font-size: 13px; opacity: 0.9; }
        .body { padding: 32px 24px; }
        .welcome { font-size: 15px; margin-bottom: 18px; color: #334155; }
        .code-box { background: #fff7ed; border: 2px dashed #f97316; border-radius: 12px; padding: 20px; text-align: center; margin: 24px 0; }
        .code-digits { font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #c2410c; font-family: monospace; }
        .code-hint { font-size: 12px; color: #9a3412; margin-top: 8px; }
        .instructions { font-size: 13px; color: #64748b; line-height: 1.6; border-top: 1px solid #f1f5f9; padding-top: 20px; margin-top: 24px; }
        .footer { background: #f8fafc; padding: 16px 24px; text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <h1>مجمع لا بروفيدانس المدرسي</h1>
            <p>بوابة أولياء الأمور والتلاميذ</p>
        </div>
        <div class="body">
            <p class="welcome">مرحباً بك يا <strong>{$pseudo}</strong>،</p>
            <p style="font-size: 14px; color: #475569; line-height: 1.6;">
                لقد طلبت تفعيل حسابك كولي أمر في منظومة المدرسة. يرجى استخدام رمز التحقق التالي لإتمام التفعيل:
            </p>
            
            <div class="code-box">
                <div class="code-digits">{$code}</div>
                <div class="code-hint">الرمز صالح لمدة 15 دقيقة فقط</div>
            </div>

            <div class="instructions">
                <strong>ملاحظة هامة:</strong>
                <br>• كلمة السر الخاصة بحسابك هي رقم هاتفك المسجل لدى المدرسة.
                <br>• يمكنك دائماً تسجيل الدخول بواسطة اسم المستخدم (<strong>{$pseudo}</strong>) ورقم هاتفك.
            </div>
        </div>
        <div class="footer">
            هذه الرسالة آلية من نظام Complexe La Providence ERP — يرجى عدم الرد عليها.
        </div>
    </div>
</body>
</html>
HTML;
    }
}
