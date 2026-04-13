import js from "@eslint/js";
import globals from "globals";

export default [
    js.configs.recommended,
    {
        files: ["Subnet-Calculator/assets/app.js"],
        languageOptions: {
            ecmaVersion: 2020,
            sourceType: "script",
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            "no-unused-vars": "warn",
            "no-console": "warn",
            "prefer-const": "error",
            "eqeqeq": ["error", "always"],
        },
    },
];
