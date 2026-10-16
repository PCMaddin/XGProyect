<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => ':attribute を承認してください。',
    'accepted_if' => ':other が :value の場合、:attribute を承認してください。',
    'active_url' => ':attribute には有効な URL を指定してください。',
    'after' => ':attribute には :date より後の日付を指定してください。',
    'after_or_equal' => ':attribute には :date 以降の日付を指定してください。',
    'alpha' => ':attribute には英字のみ使用できます。',
    'alpha_dash' => ':attribute には英数字、ハイフン、アンダースコアのみ使用できます。',
    'alpha_num' => ':attribute には英数字のみ使用できます。',
    'array' => ':attribute には配列を指定してください。',
    'ascii' => ':attribute には半角英数字と記号のみ使用できます。',
    'before' => ':attribute には :date より前の日付を指定してください。',
    'before_or_equal' => ':attribute には :date 以前の日付を指定してください。',
    'between' => [
        'array' => ':attribute の項目数は :min から :max の間にしてください。',
        'file' => ':attribute のサイズは :min から :max キロバイトの間にしてください。',
        'numeric' => ':attribute は :min から :max の間にしてください。',
        'string' => ':attribute は :min 文字から :max 文字の間にしてください。',
    ],
    'boolean' => ':attribute には true か false を指定してください。',
    'confirmed' => ':attribute の確認が一致しません。',
    'current_password' => 'パスワードが正しくありません。',
    'date' => ':attribute には有効な日付を指定してください。',
    'date_equals' => ':attribute には :date と同じ日付を指定してください。',
    'date_format' => ':attribute は :format の形式で指定してください。',
    'decimal' => ':attribute は小数点以下 :decimal 桁にしてください。',
    'declined' => ':attribute を拒否してください。',
    'declined_if' => ':other が :value の場合、:attribute を拒否してください。',
    'different' => ':attribute と :other には異なる値を指定してください。',
    'digits' => ':attribute は :digits 桁にしてください。',
    'digits_between' => ':attribute は :min 桁から :max 桁の間にしてください。',
    'dimensions' => ':attribute の画像サイズが無効です。',
    'distinct' => ':attribute に重複した値があります。',
    'doesnt_end_with' => ':attribute は次のいずれかで終わってはいけません: :values。',
    'doesnt_start_with' => ':attribute は次のいずれかで始まってはいけません: :values。',
    'email' => ':attribute には有効なメールアドレスを指定してください。',
    'ends_with' => ':attribute は次のいずれかで終わる必要があります: :values。',
    'enum' => '選択された :attribute は無効です。',
    'exists' => '選択された :attribute は無効です。',
    'file' => ':attribute にはファイルを指定してください。',
    'filled' => ':attribute に値を入力してください。',
    'gt' => [
        'array' => ':attribute の項目数は :value より多くしてください。',
        'file' => ':attribute のサイズは :value キロバイトより大きくしてください。',
        'numeric' => ':attribute は :value より大きくしてください。',
        'string' => ':attribute は :value 文字より多くしてください。',
    ],
    'gte' => [
        'array' => ':attribute の項目数は :value 以上にしてください。',
        'file' => ':attribute のサイズは :value キロバイト以上にしてください。',
        'numeric' => ':attribute は :value 以上にしてください。',
        'string' => ':attribute は :value 文字以上にしてください。',
    ],
    'image' => ':attribute には画像を指定してください。',
    'in' => '選択された :attribute は無効です。',
    'in_array' => ':attribute は :other に存在する必要があります。',
    'integer' => ':attribute には整数を指定してください。',
    'ip' => ':attribute には有効な IP アドレスを指定してください。',
    'ipv4' => ':attribute には有効な IPv4 アドレスを指定してください。',
    'ipv6' => ':attribute には有効な IPv6 アドレスを指定してください。',
    'json' => ':attribute には有効な JSON 文字列を指定してください。',
    'lowercase' => ':attribute は小文字にしてください。',
    'lt' => [
        'array' => ':attribute の項目数は :value より少なくしてください。',
        'file' => ':attribute のサイズは :value キロバイトより小さくしてください。',
        'numeric' => ':attribute は :value より小さくしてください。',
        'string' => ':attribute は :value 文字より少なくしてください。',
    ],
    'lte' => [
        'array' => ':attribute の項目数は :value 以下にしてください。',
        'file' => ':attribute のサイズは :value キロバイト以下にしてください。',
        'numeric' => ':attribute は :value 以下にしてください。',
        'string' => ':attribute は :value 文字以下にしてください。',
    ],
    'mac_address' => ':attribute には有効な MAC アドレスを指定してください。',
    'max' => [
        'array' => ':attribute の項目数は :max 以下にしてください。',
        'file' => ':attribute のサイズは :max キロバイト以下にしてください。',
        'numeric' => ':attribute は :max 以下にしてください。',
        'string' => ':attribute は :max 文字以下にしてください。',
    ],
    'max_digits' => ':attribute は :max 桁以下にしてください。',
    'mimes' => ':attribute には次の形式のファイルを指定してください: :values。',
    'mimetypes' => ':attribute には次の形式のファイルを指定してください: :values。',
    'min' => [
        'array' => ':attribute の項目数は :min 以上にしてください。',
        'file' => ':attribute のサイズは :min キロバイト以上にしてください。',
        'numeric' => ':attribute は :min 以上にしてください。',
        'string' => ':attribute は :min 文字以上にしてください。',
    ],
    'min_digits' => ':attribute は :min 桁以上にしてください。',
    'missing' => ':attribute は存在してはいけません。',
    'missing_if' => ':other が :value の場合、:attribute は存在してはいけません。',
    'missing_unless' => ':other が :value でない限り、:attribute は存在してはいけません。',
    'missing_with' => ':values が存在する場合、:attribute は存在してはいけません。',
    'missing_with_all' => ':values がすべて存在する場合、:attribute は存在してはいけません。',
    'multiple_of' => ':attribute は :value の倍数にしてください。',
    'not_in' => '選択された :attribute は無効です。',
    'not_regex' => ':attribute の形式が無効です。',
    'numeric' => ':attribute には数値を指定してください。',
    'password' => [
        'letters' => ':attribute には少なくとも1つの文字を含めてください。',
        'mixed' => ':attribute には大文字と小文字をそれぞれ1つ以上含めてください。',
        'numbers' => ':attribute には少なくとも1つの数字を含めてください。',
        'symbols' => ':attribute には少なくとも1つの記号を含めてください。',
        'uncompromised' => '指定された :attribute はデータ漏洩で見つかっています。別の :attribute を選んでください。',
    ],
    'present' => ':attribute が存在する必要があります。',
    'prohibited' => ':attribute は禁止されています。',
    'prohibited_if' => ':other が :value の場合、:attribute は禁止されています。',
    'prohibited_unless' => ':other が :values に含まれない限り、:attribute は禁止されています。',
    'prohibits' => ':attribute があると :other は指定できません。',
    'regex' => ':attribute の形式が無効です。',
    'required' => ':attribute は必須です。',
    'required_array_keys' => ':attribute には次の項目が必要です: :values。',
    'required_if' => ':other が :value の場合、:attribute は必須です。',
    'required_if_accepted' => ':other が承認された場合、:attribute は必須です。',
    'required_unless' => ':other が :values に含まれない限り、:attribute は必須です。',
    'required_with' => ':values が存在する場合、:attribute は必須です。',
    'required_with_all' => ':values がすべて存在する場合、:attribute は必須です。',
    'required_without' => ':values が存在しない場合、:attribute は必須です。',
    'required_without_all' => ':values がいずれも存在しない場合、:attribute は必須です。',
    'same' => ':attribute と :other が一致する必要があります。',
    'size' => [
        'array' => ':attribute の項目数は :size にしてください。',
        'file' => ':attribute のサイズは :size キロバイトにしてください。',
        'numeric' => ':attribute は :size にしてください。',
        'string' => ':attribute は :size 文字にしてください。',
    ],
    'starts_with' => ':attribute は次のいずれかで始まる必要があります: :values。',
    'string' => ':attribute には文字列を指定してください。',
    'timezone' => ':attribute には有効なタイムゾーンを指定してください。',
    'unique' => ':attribute はすでに使用されています。',
    'uploaded' => ':attribute のアップロードに失敗しました。',
    'uppercase' => ':attribute は大文字にしてください。',
    'url' => ':attribute には有効な URL を指定してください。',
    'ulid' => ':attribute には有効な ULID を指定してください。',
    'uuid' => ':attribute には有効な UUID を指定してください。',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [],

];
