export function createLogger(prefix: string) {
    return (area: string, message: string, data?: any) => {
        const timestamp = new Date().toISOString().split('T')[1].split('.')[0];
        const logData = data ? (typeof data === 'object' ? JSON.parse(JSON.stringify(data)) : data) : '';
        console.info(`[RTC-TRACE][${timestamp}][${prefix}][${area}] ${message}`, logData);
        if (area === 'SFU' || area === 'SIGNAL' || area === 'ERROR') {
            const stack = new Error().stack?.split('\n')[2]?.trim() || '';
            console.debug(`[RTC-STACK][${area}] ${stack}`);
        }
    };
}
