<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAN Required</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f9f9f9;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9f9f9;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border: 1px solid #ddd;">
                    
                    <!-- HEADER SECTION -->
                    <tr>
                        <td style="background-color: #2563eb; padding: 30px 20px; text-align: center;">
                            <h1 style="margin: 0; font-size: 24px; font-weight: 600; color: #ffffff; line-height: 1.3;">⚠️ KIAN Required</h1>
                            <p style="margin: 8px 0 0 0; font-size: 13px; color: #e0e7ff; line-height: 1.4;">Upaya Hukum Keberatan Atas Hasil Penghitungan</p>
                        </td>
                    </tr>

                    <!-- ALERT BOX -->
                    <tr>
                        <td style="padding: 20px;">
                            <table width="100%" cellpadding="15" cellspacing="0" border="0" style="background-color: #fef3c7; border-left: 4px solid #f59e0b;">
                                <tr>
                                    <td>
                                        <p style="margin: 0 0 8px 0; font-weight: 600; color: #92400e; font-size: 13px;">Perhatian</p>
                                        <p style="margin: 0; font-size: 13px; color: #78350f; line-height: 1.5;">
                                            Hasil penghitungan pada tahap <strong>{{ $stageName }}</strong> menunjukkan masih ada kerugian. Anda dimungkinkan untuk mengajukan KIAN.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- CASE DETAILS SECTION -->
                    <tr>
                        <td style="padding: 20px;">
                            <h3 style="margin: 0 0 15px 0; color: #1e293b; font-size: 15px; font-weight: 600;">Informasi Kasus</h3>
                            
                            <!-- Row 1: Two columns -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="50%" style="padding: 0 10px 12px 0; vertical-align: top;">
                                        <p style="margin: 0 0 4px 0; font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Nomor Kasus</p>
                                        <p style="margin: 0; font-size: 13px; color: #1e293b; font-weight: 500;">{{ $caseNumber }}</p>
                                    </td>
                                    <td width="50%" style="padding: 0 0 12px 10px; vertical-align: top; border-left: 1px solid #cbd5e1;">
                                        <p style="margin: 0 0 4px 0; font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Jenis Kasus</p>
                                        <p style="margin: 0; font-size: 13px; color: #1e293b; font-weight: 500;">{{ $caseType }}</p>
                                    </td>
                                </tr>
                                
                                <!-- Row 2: Two columns -->
                                <tr>
                                    <td style="padding: 12px 10px 12px 0; vertical-align: top; border-top: 1px solid #cbd5e1;">
                                        <p style="margin: 0 0 4px 0; font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Tahun Pajak</p>
                                        <p style="margin: 0; font-size: 13px; color: #1e293b; font-weight: 500;">{{ $caseYear ?? '-' }}</p>
                                    </td>
                                    <td style="padding: 12px 0 12px 10px; vertical-align: top; border-top: 1px solid #cbd5e1; border-left: 1px solid #cbd5e1;">
                                        <p style="margin: 0 0 4px 0; font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Wajib Pajak</p>
                                        <p style="margin: 0; font-size: 13px; color: #1e293b; font-weight: 500;">{{ $entityName }}</p>
                                    </td>
                                </tr>
                                
                                <!-- Row 3: Full width -->
                                <tr>
                                    <td colspan="2" style="padding: 12px 0 0 0; border-top: 1px solid #cbd5e1;">
                                        <p style="margin: 0 0 4px 0; font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Tahap Saat Ini</p>
                                        <p style="margin: 0; font-size: 13px; color: #1e293b; font-weight: 500;">{{ $stageName }}</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- LOSS AMOUNT SECTION -->
                    <tr>
                        <td style="padding: 0 20px 20px 20px;">
                            <table width="100%" cellpadding="15" cellspacing="0" border="0" style="background-color: #eff6ff; border-left: 4px solid #3b82f6;">
                                <tr>
                                    <td>
                                        <p style="margin: 0 0 6px 0; font-size: 11px; color: #666; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Kerugian yang Masih Tertanggung</p>
                                        <p style="margin: 0; font-size: 22px; font-weight: 700; color: #2563eb; line-height: 1.2;">{{ $currencyCode }} {{ number_format($lossAmount, 0, ',', '.') }}</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- INSTRUCTIONS SECTION -->
                    <tr>
                        <td style="padding: 0 20px 20px 20px;">
                            <table width="100%" cellpadding="15" cellspacing="0" border="0" style="background-color: #f0fdf4; border-left: 4px solid #16a34a;">
                                <tr>
                                    <td>
                                        <p style="margin: 0 0 10px 0; font-weight: 600; color: #166534; font-size: 13px;">Langkah Selanjutnya</p>
                                        <ol style="margin: 0; padding-left: 20px; color: #15803d; font-size: 13px; line-height: 1.6;">
                                            <li style="margin-bottom: 6px;">Klik tombol "Lihat Kasus" di bawah untuk membuka detail kasus</li>
                                            <li style="margin-bottom: 6px;">Review informasi kerugian yang masih tertanggung</li>
                                            <li style="margin-bottom: 6px;">Klik tombol "Ajukan KIAN" untuk memulai proses pengajuan</li>
                                            <li style="margin-bottom: 6px;">Lengkapi data KIAN sesuai dengan ketentuan yang berlaku</li>
                                            <li>Submit dokumen pendukung sesuai persyaratan</li>
                                        </ol>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- ACTION BUTTON -->
                    <tr>
                        <td style="padding: 0 20px 20px 20px; text-align: center;">
                            <a href="{{ $caseUrl }}" style="display: inline-block; background-color: #2563eb; color: white; padding: 12px 30px; text-decoration: none; font-weight: 600; font-size: 13px; border: 1px solid #2563eb; mso-padding-alt: 12px 30px;">Lihat Kasus</a>
                        </td>
                    </tr>

                    <!-- INFO SECTION -->
                    <tr>
                        <td style="padding: 20px; background-color: #f3f4f6; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 12px; color: #666; line-height: 1.6;">
                                <strong>Catatan:</strong> Email ini dikirimkan secara otomatis oleh sistem PorTax. Jika Anda memiliki pertanyaan atau memerlukan bantuan, silakan hubungi tim administrasi pajak kami.
                            </p>
                        </td>
                    </tr>

                    <!-- FOOTER -->
                    <tr>
                        <td style="background-color: #f9fafb; padding: 20px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280; line-height: 1.5;">
                            <p style="margin: 0 0 6px 0;">© {{ date('Y') }} PorTax Management System. Semua hak cipta dilindungi.</p>
                            <p style="margin: 0;">Email ini dikirimkan kepada Anda karena Anda terkait dengan kasus pajak ini.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
