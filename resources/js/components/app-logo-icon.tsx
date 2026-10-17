import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="8 8 48 48" xmlns="http://www.w3.org/2000/svg">
            <path
                d="M21 32 43 20M21 32l22 12"
                stroke="currentColor"
                strokeWidth="4"
                strokeLinecap="round"
                fill="none"
            />
            <circle cx="20" cy="32" r="7" />
            <circle cx="44" cy="19" r="7" />
            <circle cx="44" cy="45" r="7" />
        </svg>
    );
}
