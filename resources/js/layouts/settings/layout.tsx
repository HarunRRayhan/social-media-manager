import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';

// Navigation for these pages lives in the sidebar, so this only frames the content.
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { component, props } = usePage();
    const isTeam = component.startsWith('team/');

    return (
        <div className="px-4 py-6">
            <Heading
                title={isTeam ? props.auth.currentTeam.name : 'Account'}
                description={
                    isTeam
                        ? 'Manage your team, its social sets and API tokens'
                        : 'Manage your profile and account settings'
                }
            />

            <section className="max-w-2xl space-y-12">{children}</section>
        </div>
    );
}
