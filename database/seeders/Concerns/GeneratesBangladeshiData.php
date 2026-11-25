<?php

namespace Database\Seeders\Concerns;

/**
 * Realistic Bangladeshi names, phone numbers and addresses for demo data.
 * Uses mt_rand, so calling mt_srand(<number>) first makes the output repeatable.
 */
trait GeneratesBangladeshiData
{
    protected array $maleNames = [
        'Abdul', 'Karim', 'Rahim', 'Jamal', 'Kamal', 'Harun', 'Mizanur', 'Shafiqul', 'Anisur', 'Rafiqul',
        'Nurul', 'Faruk', 'Salim', 'Habib', 'Masud', 'Sohel', 'Imran', 'Tanvir', 'Rashed', 'Mahmud',
        'Shahidul', 'Zahid', 'Arif', 'Tarek', 'Monir', 'Subrata', 'Bikash', 'Tapan', 'Prodip', 'Shuvo',
        'Rakib', 'Sabbir', 'Nayeem', 'Fahim', 'Ashik', 'Raihan', 'Mehedi', 'Sakib', 'Tamim', 'Mushfiq',
        'Ariful', 'Jahid', 'Liton', 'Sumon', 'Hasan', 'Rubel', 'Shamim', 'Jewel', 'Alamgir', 'Humayun',
    ];

    protected array $femaleNames = [
        'Rokeya', 'Fatema', 'Ayesha', 'Nasrin', 'Shirin', 'Sultana', 'Parvin', 'Rahima', 'Salma', 'Rubina',
        'Shapla', 'Jesmin', 'Morjina', 'Taslima', 'Nusrat', 'Farzana', 'Shamima', 'Kulsum', 'Halima', 'Afroza',
        'Sharmin', 'Lipi', 'Mitu', 'Popy', 'Ruma', 'Shilpi', 'Moushumi', 'Tania', 'Sumaiya', 'Maliha',
        'Jannat', 'Tasnim', 'Mim', 'Ritu', 'Anika', 'Nabila', 'Rima', 'Sadia', 'Laboni', 'Kakoli',
        'Dipa', 'Bithi', 'Shathi', 'Munni', 'Sabina', 'Khadija', 'Mariam', 'Zinnat', 'Rehana', 'Shahnaz',
    ];

    protected array $familyNames = [
        'Ahmed', 'Hossain', 'Rahman', 'Islam', 'Khan', 'Chowdhury', 'Uddin', 'Miah', 'Sarkar', 'Das',
        'Roy', 'Paul', 'Ghosh', 'Biswas', 'Talukder', 'Mondal', 'Sheikh', 'Bhuiyan', 'Siddique', 'Karim',
        'Haque', 'Alam', 'Molla', 'Howlader', 'Pramanik', 'Majumder', 'Bepari', 'Sikder', 'Khondaker', 'Akand',
    ];

    protected array $femaleLast = ['Begum', 'Akter', 'Khatun', 'Jahan', 'Banu', 'Sultana'];

    protected array $areas = [
        'Mirpur', 'Uttara', 'Mohammadpur', 'Dhanmondi', 'Savar', 'Tongi', 'Narayanganj', 'Gazipur Sadar', 'Kishoreganj Sadar',
        'Comilla Sadar', 'Chandpur Sadar', 'Feni Sadar', 'Noakhali Sadar', 'Bogura Sadar', 'Rajshahi Sadar', 'Pabna Sadar',
        'Khulna Sadar', 'Jashore Sadar', 'Barishal Sadar', 'Sylhet Sadar', 'Habiganj Sadar', 'Rangpur Sadar', 'Mymensingh Sadar',
    ];

    protected array $bloodGroups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];

    protected function pick(array $list)
    {
        return $list[mt_rand(0, count($list) - 1)];
    }

    protected function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    /** @return array{first:string,last:string,full:string} */
    protected function personName(string $gender, ?string $family = null): array
    {
        $family ??= $this->pick($this->familyNames);

        if ($gender === 'female') {
            $first = $this->pick($this->femaleNames);
            $last  = $this->chance(60) ? $this->pick($this->femaleLast) : $family;
        } else {
            $first = $this->pick($this->maleNames);
            $last  = $family;
        }

        return ['first' => $first, 'last' => $last, 'full' => "{$first} {$last}"];
    }

    /** A Bangladeshi mobile number, e.g. 01712345678. */
    protected function phone(): string
    {
        return '01' . $this->pick(['3', '4', '5', '6', '7', '8', '9']) . str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    protected function address(): string
    {
        return 'House ' . mt_rand(1, 120) . ', Road ' . mt_rand(1, 25) . ', ' . $this->pick($this->areas);
    }

    protected function slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '.', strtolower($text)), '.');
    }
}
