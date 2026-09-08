import type { Metadata } from 'next';
import { Geist } from 'next/font/google';
import './globals.css';

const geistSans = Geist({
  variable: '--font-geist-sans',
  subsets: ['latin'],
  preload: false,
});

export const metadata: Metadata = {
  metadataBase: new URL('https://cpstl-recruitment-system.prabhathinduwara.chatgpt.site'),
  title: 'Sign in | CPSTL Recruitment Management System',
  description: 'Secure role-based access to CPSTL recruitment workflows.',
  openGraph: {
    title: 'CPSTL Recruitment Management System',
    description: 'Secure role-based recruitment workflows.',
    images: [{ url: '/og.png', width: 1200, height: 630 }],
  },
  twitter: {
    card: 'summary_large_image',
    title: 'CPSTL Recruitment Management System',
    description: 'Secure role-based recruitment workflows.',
    images: ['/og.png'],
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en">
      <body className={`${geistSans.variable} antialiased`}>
        {children}
      </body>
    </html>
  );
}
