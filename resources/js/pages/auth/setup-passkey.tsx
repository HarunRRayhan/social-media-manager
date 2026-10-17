import { Head, router } from '@inertiajs/react';
import PasskeyRegistration from '@/components/passkey-register';
import TextLink from '@/components/text-link';
import { dashboard } from '@/routes';

export default function SetupPasskey() {
    return (
        <>
            <Head title="Set up a passkey" />

            <div className="flex flex-col items-center gap-6">
                <PasskeyRegistration
                    onSuccess={() => router.visit(dashboard())}
                />

                <TextLink href={dashboard()} className="text-sm">
                    Skip for now, I'll use email links
                </TextLink>
            </div>
        </>
    );
}

SetupPasskey.layout = {
    title: 'Set up a passkey',
    description:
        'Log in with Touch ID, Face ID or your phone next time, no email needed. You can add more later in Settings.',
};
